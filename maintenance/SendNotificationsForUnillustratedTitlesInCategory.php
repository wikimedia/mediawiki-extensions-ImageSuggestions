<?php

namespace MediaWiki\Extension\ImageSuggestions\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\Query\QueryHelper;
use CirrusSearch\SearchConfig;
use CirrusSearch\Wikimedia\WeightedTagsHooks;
use ConfigFactory;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Elastica\Scroll;
use Elastica\Search;
use Generator;
use InvalidArgumentException;
use Maintenance;
use MediaWiki\Extension\ImageSuggestions\Hooks;
use MediaWiki\Extension\ImageSuggestions\NotificationHelper;
use MediaWiki\MediaWikiServices;
use MediaWiki\Sparql\SparqlClient;
use MediaWiki\Sparql\SparqlException;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;
use MediaWiki\WikiMap\WikiMap;
use MultiHttpClient;
use MWEchoDbFactory;
use NamespaceInfo;
use Title;

class SendNotificationsForUnillustratedTitlesInCategory extends Maintenance {
	/** @var MultiHttpClient */
	private $multiHttpClient;

	/** @var UserFactory */
	private $userFactory;

	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/** @var ConfigFactory */
	private $configFactory;

	/** @var NamespaceInfo */
	private $namespaceInfo;

	/** @var SparqlClient */
	private $cirrusCategoriesClient;

	/** @var string */
	private $suggestionsUri;

	/** @var string */
	private $instanceOfUri;

	/** @var int[] */
	private $userIds = [];

	/** @var Title[] */
	private $categories = [];

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

	/** @var bool[] Map of [userId => opted-in-to-notifications] */
	private $optedInUserIds = [];

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'ImageSuggestions' );
		$this->requireExtension( 'Echo' );
		$this->requireExtension( 'CirrusSearch' );

		$this->addDescription( 'Generate notifications for unillustrated watchlisted pages' );

		$this->addOption(
			'user',
			// @codingStandardsIgnoreStart
			"Name of user to notify",
			// @codingStandardsIgnoreEnd
			true,
			true,
			false,
			true
		);
		$this->addOption(
			'category',
			// @codingStandardsIgnoreStart
			"Name of category to find unillustrated articles in",
			// @codingStandardsIgnoreEnd
			true,
			true,
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
		$this->multiHttpClient = $services->getHttpRequestFactory()->createMultiClient();
		$this->userFactory = $services->getUserFactory();
		$this->userOptionsLookup = $services->getUserOptionsLookup();
		$this->configFactory = $services->getConfigFactory();
		$this->namespaceInfo = $services->getNamespaceInfo();
		$this->cirrusCategoriesClient = $services->getService( 'CirrusCategoriesClient' );
		$this->suggestionsUri = $this->getConfig()->get( 'ImageSuggestionsSuggestionsApi' );
		$this->instanceOfUri = $this->getConfig()->get( 'ImageSuggestionsInstanceOfApi' );

		$this->userIds = array_map( function ( string $userName ) {
			$user = $this->userFactory->newFromName( $userName );
			if ( !$user || $user->getId() === 0 ) {
				throw new InvalidArgumentException( "Invalid user name: {$userName}" );
			}
			return $user->getId();
		}, (array)$this->getOption( 'user' ) );
		$this->categories = array_map( static function ( string $categoryName ) {
			return Title::newFromText( $categoryName, NS_CATEGORY );
		}, (array)$this->getOption( 'category' ) );
		$this->minConfidence = (int)$this->getOption(
			'min-confidence',
			$this->minConfidence
		);
		$this->maxNotificationsPerUser = (int)$this->getOption(
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
				$this->waitForReplication();
			}

			$title = Title::newFromID( $pageId );
			if ( !$title ) {
				continue;
			}

			$user = $this->getUserForTitle( $title );
			if ( !$user ) {
				// list of users has been exhausted (due to all of them having received more
				// than $maxNotificationsPerUser notifications) - no point continuing
				break;
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
	 * This is an adaptation of DeepcatFeature::fetchCategories
	 *
	 * @param Title[] $rootCategories
	 * @return Title[]
	 * @throws SparqlException
	 */
	private function fetchCategories( array $rootCategories ): array {
		$endpoint = $this->getConfig()->get( 'CirrusSearchCategoryEndpoint' );
		if ( empty( $endpoint ) ) {
			return $rootCategories;
		}

		$categoriesDepth = (int)$this->getConfig()->get( 'CirrusSearchCategoryDepth' );
		$categoriesLimit = (int)$this->getConfig()->get( 'CirrusSearchCategoryMax' );

		$fullCategoryUrls = array_map( static function ( Title $rootCategory ) {
			return $rootCategory->getFullURL( '', false, PROTO_CANONICAL );
		}, $rootCategories );

		$bogusTitle = Title::makeTitle( NS_CATEGORY, 'ZZ' );
		$bogusFullName = $bogusTitle->getFullURL( '', false, PROTO_CANONICAL );
		$prefix = substr( $bogusFullName, 0, -2 );

		$results = [];
		foreach ( $fullCategoryUrls as $fullCategoryUrl ) {
			$query = <<<SPARQL
SELECT ?out WHERE {
	SERVICE mediawiki:categoryTree {
		bd:serviceParam mediawiki:start <{$fullCategoryUrl}> .
		bd:serviceParam mediawiki:direction "Reverse" .
		bd:serviceParam mediawiki:depth {$categoriesDepth} .
	}
} ORDER BY ASC(?depth)
LIMIT {$categoriesLimit}
SPARQL;
			$result = $this->cirrusCategoriesClient->query( $query );

			$categories = array_map( static function ( $row ) use ( $prefix ) {
				return rawurldecode( substr( $row['out'], strlen( $prefix ) ) );
			}, $result );
			$results = array_merge( $results, $categories );
		}

		return array_map( static function ( $categoryName ) {
			return Title::newFromText( $categoryName, NS_CATEGORY );
		}, array_unique( $results ) );
	}

	/**
	 * @return Generator where every yield is a page id
	 */
	private function getPageIdsWithSuggestions(): Generator {
		/** @var SearchConfig $searchConfig */
		$searchConfig = $this->configFactory->makeConfig( 'CirrusSearch' );
		'@phan-var SearchConfig $searchConfig';
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

		try {
			$categories = $this->fetchCategories( $this->categories );
		} catch ( SparqlException $e ) {
			$this->outputChanneled(
				"Failed to expand with subcategories, moving forward with only the categories provided.\n",
				'progress'
			);
			$categories = $this->categories;
		}

		$categoriesFilter = new BoolQuery();
		$categoriesFilter->setMinimumShouldMatch( 1 );
		foreach ( $categories as $category ) {
			$categoriesFilter->addShould(
				QueryHelper::matchPage( 'category.lowercase_keyword', $category->getDBkey() )
			);
		}

		$bool = new BoolQuery();
		$bool->addFilter( $match );
		$bool->addFilter( $categoriesFilter );

		$query = new Query();
		$query->setQuery( $bool );
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

		$responses = $this->multiHttpClient->runMulti( $requests );
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
		if ( !$this->userIds ) {
			return null;
		}

		$dbr = $this->getDB( DB_REPLICA );
		$dbrEcho = MWEchoDbFactory::newFromDefault()->getEchoDb( DB_REPLICA );
		if ( !$dbr || !$dbrEcho ) {
			return null;
		}

		// list of users who have already received an image suggestion notification for this page
		$previouslyNotifiedUserIds = $dbrEcho->selectFieldValues(
			[ 'echo_notification', 'echo_event' ],
			'notification_user',
			[
				'notification_user' => $this->userIds,
				'event_type' => Hooks::EVENT_NAME,
				'event_page_id' => $title->getId()
			],
			__METHOD__,
			[ 'DISTINCT' ],
			[ 'echo_event' => [ 'INNER JOIN', 'notification_event = event_id' ] ]
		);

		// list of users who've already been notified a certain amount of times in this run
		$maxNotifiedUserIds = array_keys( array_filter( $this->notifiedUserIds, function ( $amount ) {
			return $amount >= $this->maxNotificationsPerUser;
		} ) );

		// list of users who have opted out of receiving any kind of image suggestions notification
		$optedOutUserIds = array_keys( array_filter( $this->optedInUserIds, static function ( $value ) {
			return $value !== true;
		} ) );

		$availableUserIds = array_diff(
			$this->userIds,
			$previouslyNotifiedUserIds,
			$maxNotifiedUserIds,
			$optedOutUserIds
		);
		if ( !$availableUserIds ) {
			return null;
		}

		// get user that has not opted out of image suggestions notifications or
		// otherwise already excluded, preferring those with most recent edits to page
		$userIds = $dbr->selectFieldValues(
			[
				'user',
				'actor',
				'revision',
			],
			'DISTINCT user_id',
			[ 'user_id' => $availableUserIds ],
			__METHOD__,
			[
				'ORDER BY rev_timestamp DESC',
				'LIMIT' => 1000,
			],
			[
				'actor' => [ 'INNER JOIN', 'actor_user = user_id' ],
				'revision' => [ 'LEFT JOIN', [ 'rev_page' => $title->getId(), 'rev_actor' => 'actor_id' ] ],
			]
		);

		// iterate users to figure out whether they've opted in to any type of notifications
		// for this event, and store the known results in $this->optedInUserIds so we can
		// easily exclude these for the next result right away.
		// we can't do this in the query because not all these options are available in the
		// same database: GlobalPreferences may live elsewhere
		foreach ( $userIds as $userId ) {
			$user = $this->userFactory->newFromId( $userId );

			// check whether user is already known to have opted in
			if ( $this->optedInUserIds[$userId] ?? false ) {
				return $user;
			}

			foreach ( [ 'web', 'email', 'push' ] as $type ) {
				$optionName = "echo-subscriptions-$type-" . Hooks::EVENT_NAME;
				if ( $this->userOptionsLookup->getOption( $user, $optionName ) ) {
					$this->optedInUserIds[$userId] = true;
					return $user;
				}
			}
			$this->optedInUserIds[$userId] = false;
		}

		return null;
	}

	protected function createNotification( UserIdentity $user, Title $title, string $mediaUrl ) {
		if ( $this->verbose && !$this->isQuiet() ) {
			// note that this is duplication of what is already implemented in
			// NotificationHelper::createNotification, but that one sends to
			// a `LoggerInterface`, while this uses convoluted `print` mechanics
			// defined in `Maintenance`, and I couldn't bother checking whether
			// mixing both wouldn't lead to odd results
			$this->outputChanneled(
				"Notification: " .
				"user: {$user->getName()} (id: {$user->getId()}), " .
				"title: {$title->getFullText()} (id: {$title->getId()}), " .
				"media: {$mediaUrl}\n",
				'verbose'
			);
		}

		$notificationHelper = new NotificationHelper();
		$notificationHelper->createNotification(
			$user,
			$title,
			$mediaUrl,
			null,
			null,
			$this->dryRun
		);
	}
}

$maintClass = SendNotificationsForUnillustratedTitlesInCategory::class;
require_once RUN_MAINTENANCE_IF_MAIN;
