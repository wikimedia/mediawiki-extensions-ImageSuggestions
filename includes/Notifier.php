<?php

namespace MediaWiki\Extension\ImageSuggestions;

use CirrusSearch\Connection;
use CirrusSearch\Elastica\SearchAfter;
use CirrusSearch\Search\WeightedTagsHooks;
use CirrusSearch\SearchConfig;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Elastica\ResultSet;
use Elastica\Search;
use MediaWiki\Config\Config;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Psr\Log\LoggerInterface;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class Notifier {
	private array $jobParams;

	private SearchAfter $searchAfter;

	public const MAX_SECTION_SUGGESTIONS_PER_NOTIFICATION = 5;

	/**
	 * @codeCoverageIgnore
	 */
	public function __construct(
		private readonly string $suggestionsUri,
		private readonly string $instanceOfUri,
		private readonly MultiHttpClient $multiHttpClient,
		private readonly UserFactory $userFactory,
		private readonly UserOptionsLookup $userOptionsLookup,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly IReadableDatabase $dbr,
		private readonly IReadableDatabase $dbrEcho,
		private readonly LoggerInterface $logger,
		private readonly Config $searchConfig,
		private readonly Connection $searchConnection,
		private readonly TitleFactory $titleFactory,
		private readonly NotificationHelper $notificationHelper,
		private readonly WikiMapHelper $wikiMapHelper,
		array $jobParams,
	) {
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

		$articleImageQuery = new MatchQuery();
		$articleImageQuery->setFieldQuery(
			WeightedTagsHooks::FIELD_NAME,
			'recommendation.image/exists'
		);
		$sectionImageQuery = new MatchQuery();
		$sectionImageQuery->setFieldQuery(
			WeightedTagsHooks::FIELD_NAME,
			'recommendation.image_section/exists'
		);
		$bool = new BoolQuery();
		$bool->addShould( $articleImageQuery );
		$bool->addShould( $sectionImageQuery );
		$bool->setMinimumShouldMatch( 1 );

		$query = new Query();
		$query->setQuery( $bool );
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
			if ( !$suggestions ) {
				$this->logger->debug( 'No suggestions found for ' . $pageId );
				continue;
			}

			$this->jobParams['notifiedUserIds'][$user->getId()] =
				( $this->jobParams['notifiedUserIds'][$user->getId()] ?? 0 ) + 1;

			// If we have a bundle of notifications the newest ones are displayed first.
			// Reverse the order of the array so that the elements earlier in the array are
			// created later (and therefore are newer and get displayed earlier)
			foreach ( array_reverse( $suggestions ) as $suggestion ) {
				$this->notificationHelper->createNotification(
					$user,
					$title,
					$this->getMediaUrl( $suggestion ),
					$this->getSectionHeading( $suggestion ),
					$this->jobParams['verbose'] ? $this->logger : null,
					$this->jobParams['dryRun'],
				);
			}
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

	private function getSectionHeading( array $suggestion ): ?string {
		return $suggestion['section_heading'];
	}

	private function isArticleLevelSuggestion( array $suggestion ): bool {
		return $this->getSectionHeading( $suggestion ) === null;
	}

	private function isSectionLevelSuggestion( array $suggestion ): bool {
		return !$this->isArticleLevelSuggestion( $suggestion );
	}

	private function getMediaUrl( array $suggestion ): string {
		return $this->wikiMapHelper->getForeignURL(
			$suggestion['origin_wiki'],
			$this->namespaceInfo->getCanonicalName( NS_FILE ) . ':' .
			$suggestion['image']
		);
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Platform_Engineering_Team/Data_Value_Stream/Data_Gateway#Suggestions
	 * @see https://www.mediawiki.org/wiki/Platform_Engineering_Team/Data_Value_Stream/Data_Gateway#Instanceof_(cache)
	 * @param int $pageId
	 * @return array of filtered suggestions
	 * 	- the first element is the first article-level suggestion (sorted by confidence), if one exists
	 * 	- followed by up to MAX_SECTION_SUGGESTIONS_PER_NOTIFICATION section-level suggestions
	 * 		- initially ordered by confidence, so we return the suggestions with the highest confidence
	 * 		- then re-ordered so section-suggestions are in the same order as the sections on the page
	 * 	- each value being a row structured as per 1st @see
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

		// if the page is an instance of an entity we wish to exclude, then filter out *article*
		// level suggestions only
		$filterArticleSuggestions = false;
		if ( array_intersect( $this->jobParams['excludeInstanceOf'], $results[1]['rows'][0]['instance_of'] ?? [] ) ) {
			// page is an instance of an entity that we wish to exclude; return empty resultset
			$filterArticleSuggestions = true;
		}

		$results = array_filter(
			$results[0]['rows'] ?? [],
			function ( array $row ) use ( $filterArticleSuggestions ) {
				if ( $filterArticleSuggestions && $this->isArticleLevelSuggestion( $row ) ) {
					return false;
				}
				return $this->isArticleLevelSuggestion( $row ) ?
					$row['confidence'] >= $this->jobParams['minConfidence'] :
					$row['confidence'] >= $this->jobParams['minConfidenceSection'];
			}
		);

		usort(
			$results,
			static function ( array $a, array $b ) {
				return $b['confidence'] <=> $a['confidence'];
			}
		);

		// only 1 suggestion per section
		$results = array_values( array_reduce(
			$results,
			static function ( array $carry, array $row ) {
				if ( !isset( $carry[$row['section_heading']] ) ) {
					$carry[(string)$row['section_heading']] = $row;
				}
				return $carry;
			},
			[]
		) );

		$articleSuggestion = array_slice(
			array_filter( $results, [ $this, 'isArticleLevelSuggestion' ] ), 0, 1
		);
		$sectionSuggestions = array_slice(
			array_filter( $results, [ $this, 'isSectionLevelSuggestion' ] ),
			0,
			self::MAX_SECTION_SUGGESTIONS_PER_NOTIFICATION
		);
		usort(
			$sectionSuggestions,
			static function ( array $a, array $b ) {
				return (int)$a['section_index'] <=> (int)$b['section_index'];
			}
		);
		return array_merge( $articleSuggestion, $sectionSuggestions );
	}

	/**
	 * @param Title $title
	 * @return UserIdentity|null
	 */
	private function getUserForTitle( Title $title ): ?UserIdentity {
		// list of users who have already received an image suggestion notification for this page
		$previouslyNotifiedUserIds = $this->dbrEcho->newSelectQueryBuilder()
			->select( 'notification_user' )
			->distinct()
			->from( 'echo_notification' )
			->join( 'echo_event', null, 'notification_event = event_id' )
			->where( [
				'event_type' => Hooks::EVENT_NAME,
				'event_page_id' => $title->getId()
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();

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

		$userIds = $this->dbr->newSelectQueryBuilder()
			->select( 'wl_user' )
			->distinct()
			->from( 'watchlist' )
			->join( 'user', null, 'user_id = wl_user' )
			->join( 'actor', null, 'actor_user = wl_user' )
			->join( 'page', null, [ 'page_namespace = wl_namespace', 'page_title = wl_title' ] )
			->leftJoin( 'revision', null, [ 'rev_page = page_id', 'rev_actor' => 'actor_id' ] )
			->where( $excludeUserIds ? [ $this->dbr->expr( 'wl_user', '!=', $excludeUserIds ) ] : [] )
			->andWhere( [
				'wl_namespace' => $title->getNamespace(),
				'wl_title' => $title->getDBkey(),
				$this->dbr->expr( 'user_editcount', '>=', (int)$this->jobParams['minEditCount'] ),
			] )
			->orderBy( 'rev_timestamp', SelectQueryBuilder::SORT_DESC )
			->limit( 1000 )
			->caller( __METHOD__ )
			->fetchFieldValues();

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
