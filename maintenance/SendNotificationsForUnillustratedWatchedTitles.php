<?php

namespace MediaWiki\Extension\ImageSuggestions\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use CirrusSearch\Wikimedia\WeightedTagsHooks;
use Elastica\Query;
use Elastica\Query\MatchQuery;
use Elastica\Scroll;
use Elastica\Search;
use Generator;
use MediaWiki\Extension\ImageSuggestions\Hooks;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;
use MWEchoDbFactory;
use MWException;
use NamespaceInfo;
use Title;
use WikiMap;
use Wikimedia\Rdbms\LBFactory;

require_once __DIR__ . '/AbstractNotifications.php';

class SendNotificationsForUnillustratedWatchedTitles extends AbstractNotifications {
	/** @var LBFactory */
	private $loadBalancerFactory;

	/** @var UserFactory */
	private $userFactory;

	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/** @var NamespaceInfo */
	private $namespaceInfo;

	/** @var string */
	private $suggestionsUri;

	/** @var string */
	private $instanceOfUri;

	/** @var int */
	private $minEditCount = 500;

	/** @var int */
	private $minConfidence = 0;

	/** @var int */
	private $maxNotificationsPerUser = 2;

	/** @var string[] */
	private $excludeInstanceOf = [];

	/** @var bool */
	private $dryRun = false;

	/** @var bool */
	private $verbose = false;

	/** @var int[] Map of [userId => number-of-notifications] */
	private $notifiedUserIds = [];

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'CirrusSearch' );

		$this->addDescription( 'Generate notifications for unillustrated watchlisted pages' );

		$this->addOption(
			'min-edit-count',
			"Minimum edit count for users to receive notification, default: $this->minEditCount",
			false,
			true
		);
		$this->addOption(
			'min-confidence',
			// @codingStandardsIgnoreStart
			"Minimum confidence score (0-100) required to send notification for suggestion, default: $this->minConfidence",
			// @codingStandardsIgnoreEnd
			false,
			true
		);
		$this->addOption(
			'max-notifications-per-user',
			// @codingStandardsIgnoreStart
			"Maximum amount of notifications to create per user, per run of this script, default: $this->maxNotificationsPerUser",
			// @codingStandardsIgnoreEnd
			false,
			true
		);
		$defaultExcludeInstanceOf = $this->excludeInstanceOf ? implode( ', ', $this->excludeInstanceOf ) : '<none>';
		$this->addOption(
			'exclude-instance-of',
			// @codingStandardsIgnoreStart
			"Item Q-id of which the page's associated entity must not be an instance of, default: $defaultExcludeInstanceOf",
			// @codingStandardsIgnoreEnd
			false,
			true,
			false,
			true
		);
		$this->addOption(
			'dry-run',
			'Prevent notifications from being sent'
		);
		$this->addOption(
			'verbose',
			'Output details of each notification being sent'
		);

		$this->setBatchSize( 100 );
	}

	public function init() {
		$services = MediaWikiServices::getInstance();
		$this->loadBalancerFactory = $services->getDBLoadBalancerFactory();
		$this->userFactory = $services->getUserFactory();
		$this->userOptionsLookup = $services->getUserOptionsLookup();
		$this->namespaceInfo = $services->getNamespaceInfo();
		$this->suggestionsUri = $this->getConfig()->get( 'ImageSuggestionsSuggestionsApi' );
		$this->instanceOfUri = $this->getConfig()->get( 'ImageSuggestionsInstanceOfApi' );

		$this->minEditCount = (float)$this->getOption(
			'min-edit-count',
			$this->minEditCount
		);
		$this->minConfidence = (float)$this->getOption(
			'min-confidence',
			$this->minConfidence
		);
		$this->maxNotificationsPerUser = (float)$this->getOption(
			'max-notifications-per-user',
			$this->maxNotificationsPerUser
		);
		$this->excludeInstanceOf = (array)$this->getOption(
			'exclude-instance-of',
			$this->excludeInstanceOf
		);
		$this->dryRun = (bool)$this->getOption(
			'dry-run',
			$this->dryRun
		);
		$this->verbose = (bool)$this->getOption(
			'verbose',
			$this->verbose
		);
	}

	public function execute() {
		$this->init();

		$pageIds = $this->getPageIdsWithSuggestions();
		$numPages = 0;
		foreach ( $pageIds as $i => $pageId ) {
			$numPages++;
			$isNewBatch = $i % $this->mBatchSize === 0;

			if ( $isNewBatch ) {
				$this->outputChanneled(
					'Batch #' . ( $i / $this->mBatchSize + 1 ) . '(' . $i . '-' . ( $i + $this->mBatchSize ) . ").\n",
					'progress'
				);
				$this->loadBalancerFactory->waitForReplication();
			}

			$title = Title::newFromID( $pageId );
			if ( !$title ) {
				continue;
			}

			$user = $this->getUserForTitle( $title );
			if ( !$user ) {
				continue;
			}

			$suggestions = $this->getSuggestions( $pageId );
			$suggestion = array_shift( $suggestions );
			if ( !$suggestion ) {
				continue;
			}

			$this->notifiedUserIds[$user->getId()] = ( $this->notifiedUserIds[$user->getId()] ?? 0 ) + 1;
			$this->createNotification(
				$user,
				$title,
				WikiMap::getForeignURL(
					$suggestion['origin_wiki'],
					$this->namespaceInfo->getCanonicalName( NS_FILE ) . ':' . $suggestion['image']
				)
			);
		}

		$numUsers = count( $this->notifiedUserIds );
		$numNotifications = array_sum( $this->notifiedUserIds );
		$numMissing = $numPages - $numNotifications;
		$this->outputChanneled(
			"Done. " .
			"Notified {$numUsers} users about {$numNotifications} pages. " .
			"{$numMissing} pages had no available users.",
			'progress'
		);
	}

	/**
	 * @return Generator where every yield is a page id
	 * @throws MWException
	 */
	private function getPageIdsWithSuggestions(): Generator {
		/** @var SearchConfig $searchConfig */
		$searchConfig = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CirrusSearch' );
		$connection = Connection::getPool( $searchConfig );
		$client = $connection->getClient();
		$index = $connection->getIndex(
			$searchConfig->get( SearchConfig::INDEX_BASE_NAME ),
			$connection->pickIndexSuffixForNamespaces( $searchConfig->get( 'ContentNamespaces' ) )
		);

		$match = new MatchQuery();
		$match->setFieldQuery(
			WeightedTagsHooks::FIELD_NAME,
			'recommendation.image/exists'
		);

		$query = new Query();
		$query->setQuery( $match );
		$query->setSize( $this->mBatchSize );
		$query->setSource( false );
		$query->setSort( [ '_doc' ] );

		$search = new Search( $client );
		$search->setQuery( $query );
		$search->addIndex( $index );

		$scroll = new Scroll( $search, '3h' );
		foreach ( $scroll as $results ) {
			foreach ( $results as $result ) {
				yield $searchConfig->makePageId( $result->getId() );
			}
		}
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Platform_Engineering_Team/Data_Value_Stream/Data_Gateway#Suggestions
	 * @see https://www.mediawiki.org/wiki/Platform_Engineering_Team/Data_Value_Stream/Data_Gateway#Instanceof_(cache)
	 * @param int $pageId
	 * @return array of filtered & sorted (by confidence) suggestions, each value being a row structured as per 1st @see
	 * @throws \Exception
	 */
	private function getSuggestions( int $pageId ): array {
		$currentWikiId = WikiMap::getCurrentWikiId();
		$requests = [ [ 'method' => 'GET', 'url' => sprintf( $this->suggestionsUri, $currentWikiId, $pageId ) ] ];
		if ( $this->excludeInstanceOf ) {
			$requests[] = [ 'method' => 'GET', 'url' => sprintf( $this->instanceOfUri, $currentWikiId, $pageId ) ];
		}

		$client = MediaWikiServices::getInstance()->getHttpRequestFactory()->createMultiClient();
		$responses = $client->runMulti( $requests );
		$results = array_map(
			static function ( $response ) {
				return json_decode( $response['response']['body'], true ) ?: [];
			},
			$responses
		);

		if ( array_intersect( $this->excludeInstanceOf, $results[1]['rows'][0]['instance_of'] ?? [] ) ) {
			// page is an instance of an entity that we wish to exclude; return empty resultset
			return [];
		}

		$results = array_filter(
			$results[0]['rows'] ?? [],
			function ( array $row ) {
				return $row['confidence'] >= $this->minConfidence;
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
		$dbr = $this->getDB( DB_REPLICA );
		$dbrEcho = MWEchoDbFactory::newFromDefault()->getEchoDb( DB_REPLICA );
		if ( !$dbr || !$dbrEcho ) {
			return null;
		}

		// list of users who have already received an image suggestion notification for this page
		$previouslyNotifiedUserIds = $dbrEcho->selectFieldValues(
			[ 'echo_notification', 'echo_event' ],
			'notification_user',
			[ 'event_type' => Hooks::EVENT_NAME, 'event_page_id' => $title->getId() ],
			__METHOD__,
			[ 'DISTINCT' ],
			[ 'echo_event' => [ 'INNER JOIN', 'notification_event = event_id' ] ]
		);

		// list of users who've already been notified a certain amount of times in this run
		$maxNotifiedUserIds = array_keys( array_filter( $this->notifiedUserIds, function ( $amount ) {
			return $amount >= $this->maxNotificationsPerUser;
		} ) );

		$excludeUserIds = array_merge( $previouslyNotifiedUserIds, $maxNotifiedUserIds );

		$types = [ 'web', 'email', 'push' ];
		$notificationOptions = array_map( static function ( $type ) {
			return "echo-subscriptions-$type-" . Hooks::EVENT_NAME;
		}, $types );
		$notificationDefaultValues = array_map( function ( $option ) {
			return $this->userOptionsLookup->getDefaultOption( $option );
		}, $notificationOptions );
		$notificationOptionsTableAliases = array_map( static function ( $type ) {
			return "up{$type}";
		}, $types );

		$userId = $dbr->selectField(
			array_merge( [
				'watchlist',
				'user',
				'actor',
				'page',
				'revision',
			],
			// below builds an array like [ 'upweb' => 'user_properties', ... ]
			array_fill_keys( $notificationOptionsTableAliases, 'user_properties' ) ),
			'DISTINCT wl_user',
			array_merge(
				$excludeUserIds ? [ 'wl_user NOT IN (' . $dbr->makeList( $excludeUserIds ) . ')' ] : [],
				[
					'wl_namespace' => $title->getNamespace(),
					'wl_title' => $title->getDBkey(),
					"user_editcount >= {$this->minEditCount}",
					$dbr->makeList(
						// below builds an array like [ 'upweb.up_value = 1 OR up_value IS NULL', ... ]
						array_map( static function ( $option, $alias ) {
							// if notification is enabled by default, we may have no up_property row
							return "{$alias}.up_value = 1" . ( $option ? " OR {$alias}.up_value IS NULL" : '' );
						}, $notificationDefaultValues, $notificationOptionsTableAliases ),
						$dbr::LIST_OR
					),
				]
			),
			__METHOD__,
			[
				'ORDER BY rev_timestamp DESC',
				'LIMIT' => 1,
			],
			array_merge(
				[
					'user' => [ 'INNER JOIN', 'user_id = wl_user' ],
					'actor' => [ 'INNER JOIN', 'actor_user = wl_user' ],
					'page' => [ 'INNER JOIN', [ 'page_namespace = wl_namespace', 'page_title = wl_title' ] ],
					'revision' => [ 'LEFT JOIN', [ 'rev_page = page_id', 'rev_actor' => 'actor_id' ] ],
				],
				// below builds an array like [ 'upweb' => [ 'LEFT JOIN', [ ... ] ], ... ]
				array_combine(
					$notificationOptionsTableAliases,
					array_map( static function ( $option, $alias ) {
						return [ 'LEFT JOIN', [ "{$alias}.up_user = user_id", "{$alias}.up_property" => $option ] ];
					}, $notificationOptions, $notificationOptionsTableAliases )
				)
			)
		);

		if ( $userId === false ) {
			return null;
		}
		return $this->userFactory->newFromId( $userId );
	}

	protected function createNotification( UserIdentity $user, Title $title, string $mediaUrl ) {
		if ( $this->verbose && !$this->isQuiet() ) {
			$this->outputChanneled(
				"Notification: " .
				"user: {$user->getName()} (id: {$user->getId()}), " .
				"title: {$title->getFullText()} (id: {$title->getId()}), " .
				"media: {$mediaUrl}\n",
				'verbose'
			);
		}

		if ( $this->dryRun ) {
			return false;
		}

		return parent::createNotification( $user, $title, $mediaUrl );
	}
}

$maintClass = SendNotificationsForUnillustratedWatchedTitles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
