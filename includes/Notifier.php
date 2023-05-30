<?php

namespace MediaWiki\Extension\ImageSuggestions;

use CirrusSearch\Connection;
use CirrusSearch\Elastica\SearchAfter;
use CirrusSearch\SearchConfig;
use CirrusSearch\Wikimedia\WeightedTagsHooks;
use Config;
use Elastica\Query;
use Elastica\Query\MatchQuery;
use Elastica\ResultSet;
use Elastica\Search;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;
use MultiHttpClient;
use NamespaceInfo;
use Psr\Log\LoggerInterface;
use Title;
use WikiMap;
use Wikimedia\Rdbms\IReadableDatabase;

class Notifier {
	private MultiHttpClient $multiHttpClient;
	private UserFactory $userFactory;
	private UserOptionsLookup $userOptionsLookup;
	private NamespaceInfo $namespaceInfo;
	private string $suggestionsUri;
	private string $instanceOfUri;
	private LoggerInterface $logger;
	private IReadableDatabase $dbr;
	private IReadableDatabase $dbrEcho;
	private Config $searchConfig;
	private Connection $searchConnection;
	private TitleFactory $titleFactory;
	private NotificationHelper $notificationHelper;
	private WikiMapHelper $wikiMapHelper;
	private array $jobParams;

	private SearchAfter $searchAfter;

	/**
	 * @codeCoverageIgnore
	 */
	public function __construct(
		string $suggestionsUri,
		string $instanceOfUri,
		MultiHttpClient $multiHttpClient,
		UserFactory $userFactory,
		UserOptionsLookup $userOptionsLookup,
		NamespaceInfo $namespaceInfo,
		IReadableDatabase $mainDbConnection,
		IReadableDatabase $echoDbConnection,
		LoggerInterface $logger,
		Config $searchConfig,
		Connection $searchConnection,
		TitleFactory $titleFactory,
		NotificationHelper $notificationHelper,
		WikiMapHelper $wikiMapHelper,
		array $jobParams
	) {
		$this->suggestionsUri = $suggestionsUri;
		$this->instanceOfUri = $instanceOfUri;

		$this->multiHttpClient = $multiHttpClient;
		$this->userFactory = $userFactory;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->namespaceInfo = $namespaceInfo;

		$this->dbr = $mainDbConnection;
		$this->dbrEcho = $echoDbConnection;
		$this->logger = $logger;

		$this->searchConfig = $searchConfig;
		$this->searchConnection = $searchConnection;
		$this->titleFactory = $titleFactory;
		$this->notificationHelper = $notificationHelper;
		$this->wikiMapHelper = $wikiMapHelper;

		$this->jobParams = [ 'numPages' => 0 ] + $jobParams;

		$this->searchAfter = $this->createSearchAfter();
	}

	private function createSearchAfter(): SearchAfter {
		$searchClient = $this->searchConnection->getClient();
		$searchIndex = $this->searchConnection->getIndex(
			$this->searchConfig->get( SearchConfig::INDEX_BASE_NAME ),
			$this->searchConnection->pickIndexSuffixForNamespaces(
				$this->searchConfig->get( 'ContentNamespaces' )
			)
		);

		$match = new MatchQuery();
		$match->setFieldQuery(
			WeightedTagsHooks::FIELD_NAME,
			'recommendation.image/exists'
		);

		$query = new Query();
		$query->setQuery( $match );
		$query->setSize( $this->jobParams['batchSize'] );
		$query->setSource( false );
		$query->setSort( [ 'page_id' ] );
		$query->setStoredFields( [ '_id' ] );

		$search = new Search( $searchClient );
		$search->setQuery( $query );
		$search->addIndex( $searchIndex );

		$searchAfter = new SearchAfter( $search );
		if ( $this->jobParams['lastPageId'] > 0 ) {
			$searchAfter->initializeSearchAfter( [ $this->jobParams['lastPageId'] ] );
		}
		return $searchAfter;
	}

	private function doSearch(): ResultSet {
		$this->searchAfter->rewind();
		return $this->searchAfter->current();
	}

	public function run(): ?array {
		$searchResults = $this->doSearch();
		if ( count( $searchResults ) === 0 ) {
			$this->logger->error( 'No more articles with suggestions found' );
			return null;
		}

		foreach ( $searchResults as $searchResult ) {
			$pageId = (int)$searchResult->getId();
			$this->jobParams['lastPageId'] = $pageId;
			$title = $this->titleFactory->newFromId( $pageId );
			if ( !$title ) {
				$this->logger->debug( 'No title found for ' . $pageId );
				continue;
			}

			$this->jobParams['numPages']++;
			$user = $this->getUserForTitle( $title );
			if ( !$user ) {
				$this->logger->debug( 'No user found for ' . $title->getDBkey() );
				continue;
			}

			$suggestions = $this->getSuggestions( $pageId );
			$suggestion = array_shift( $suggestions );
			if ( !$suggestion ) {
				$this->logger->debug( 'No suggestions found for ' . $pageId );
				continue;
			}

			$this->jobParams['notifiedUserIds'][$user->getId()] =
				( $this->jobParams['notifiedUserIds'][$user->getId()] ?? 0 ) + 1;
			$this->notificationHelper->createNotification(
				$user,
				$title,
				$this->wikiMapHelper->getForeignURL(
					$suggestion['origin_wiki'],
					$this->namespaceInfo->getCanonicalName( NS_FILE ) . ':' .
					$suggestion['image']
				),
				$this->jobParams['verbose'] ? $this->logger : null,
				$this->jobParams['dryRun'],
			);
		}

		$numUsers = count( $this->jobParams['notifiedUserIds'] );
		$numNotifications = array_sum( $this->jobParams['notifiedUserIds'] );
		$numMissing = $this->jobParams['numPages'] - $numNotifications;
		$this->logger->info(
			"Finished job. " .
			"In total have notified {$numUsers} users about {$numNotifications} pages. " .
			"Notifications not sent for {$numMissing} pages as they had no available users " .
			"or the suggestions were excluded or didn't meet the confidence threshold."
		);

		return $this->jobParams;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Platform_Engineering_Team/Data_Value_Stream/Data_Gateway#Suggestions
	 * @see https://www.mediawiki.org/wiki/Platform_Engineering_Team/Data_Value_Stream/Data_Gateway#Instanceof_(cache)
	 * @param int $pageId
	 * @return array of filtered & sorted (by confidence) suggestions, each value being a row structured as per 1st @see
	 */
	private function getSuggestions( int $pageId ): array {
		$currentWikiId = WikiMap::getCurrentWikiId();
		$requests = [ [ 'method' => 'GET', 'url' => sprintf( $this->suggestionsUri, $currentWikiId, $pageId ) ] ];
		if ( $this->jobParams['excludeInstanceOf'] ) {
			$requests[] = [ 'method' => 'GET', 'url' => sprintf( $this->instanceOfUri, $currentWikiId, $pageId ) ];
		}

		$responses = $this->multiHttpClient->runMulti( $requests );
		$results = array_map(
			static function ( $response ) {
				return json_decode( $response['response']['body'], true ) ?: [];
			},
			$responses
		);

		if ( array_intersect( $this->jobParams['excludeInstanceOf'], $results[1]['rows'][0]['instance_of'] ?? [] ) ) {
			// page is an instance of an entity that we wish to exclude; return empty resultset
			return [];
		}

		$results = array_filter(
			$results[0]['rows'] ?? [],
			function ( array $row ) {
				return $row['confidence'] >= $this->jobParams['minConfidence'];
			}
		);

		usort(
			$results,
			static function ( array $a, array $b ) {
				return $b['confidence'] <=> $a['confidence'];
			}
		);

		return $results;
	}

	/**
	 * @param Title $title
	 * @return UserIdentity|null
	 */
	private function getUserForTitle( Title $title ): ?UserIdentity {
		// list of users who have already received an image suggestion notification for this page
		$previouslyNotifiedUserIds = $this->dbrEcho->selectFieldValues(
			[ 'echo_notification', 'echo_event' ],
			'notification_user',
			[ 'event_type' => Hooks::EVENT_NAME, 'event_page_id' => $title->getId() ],
			__METHOD__,
			[ 'DISTINCT' ],
			[ 'echo_event' => [ 'INNER JOIN', 'notification_event = event_id' ] ]
		);

		// list of users who've already been notified a certain amount of times in this run
		$maxNotifiedUserIds = array_keys(
			array_filter(
				$this->jobParams['notifiedUserIds'],
				function ( $amount ) {
					return $amount >= $this->jobParams['maxNotificationsPerUser'];
				}
			)
		);

		// list of users who have opted out of receiving any kind of image suggestions notification
		$optedOutUserIds = array_keys(
			array_filter(
				$this->jobParams['optedInUserIds'],
				static function ( $value ) {
					return $value !== true;
				}
			)
		);

		$excludeUserIds = array_merge( $previouslyNotifiedUserIds, $maxNotifiedUserIds, $optedOutUserIds );

		$userIds = $this->dbr->selectFieldValues(
			[
				'watchlist',
				'user',
				'actor',
				'page',
				'revision',
			],
			'DISTINCT wl_user',
			array_merge(
				$excludeUserIds ? [ 'wl_user NOT IN (' . $this->dbr->makeList( $excludeUserIds ) . ')' ] : [],
				[
					'wl_namespace' => $title->getNamespace(),
					'wl_title' => $title->getDBkey(),
					"user_editcount >= " . (int)$this->jobParams['minEditCount'],
				]
			),
			__METHOD__,
			[
				'ORDER BY rev_timestamp DESC',
				'LIMIT' => 1000,
			],
			[
				'user' => [ 'INNER JOIN', 'user_id = wl_user' ],
				'actor' => [ 'INNER JOIN', 'actor_user = wl_user' ],
				'page' => [ 'INNER JOIN', [ 'page_namespace = wl_namespace', 'page_title = wl_title' ] ],
				'revision' => [ 'LEFT JOIN', [ 'rev_page = page_id', 'rev_actor' => 'actor_id' ] ],
			]
		);

		// iterate users to figure out whether they've opted in to any type of notifications
		// for this event, and store the known results in $this->jobParams['optedInUserIds'] so we can
		// easily exclude these for the next result right away.
		// we can't do this in the query because not all these options are available in the
		// same database: GlobalPreferences may live elsewhere
		foreach ( $userIds as $userId ) {
			$user = $this->userFactory->newFromId( $userId );

			// check whether user is already known to have opted in
			if ( $this->jobParams['optedInUserIds'][$userId] ?? false ) {
				return $user;
			}

			foreach ( [ 'web', 'email', 'push' ] as $type ) {
				$optionName = "echo-subscriptions-$type-" . Hooks::EVENT_NAME;
				if ( $this->userOptionsLookup->getOption( $user, $optionName ) ) {
					$this->jobParams['optedInUserIds'][$userId] = true;
					return $user;
				}
			}
			$this->jobParams['optedInUserIds'][$userId] = false;
		}

		return null;
	}
}
