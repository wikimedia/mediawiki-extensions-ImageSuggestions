<?php

namespace MediaWiki\Extension\ImageSuggestions\Maintenance;

use ApiMain;
use DerivativeContext;
use FauxRequest;
use MediaWiki\Extension\ImageSuggestions\Hooks;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;
use MWException;
use NamespaceInfo;
use RequestContext;
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
			'Output results instead of actually sending the notifications'
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
	}

	public function execute() {
		$this->init();

		$offset = 0;
		do {
			$this->outputChanneled(
				'Batch #' . ( floor( $offset / $this->mBatchSize ) + 1 ),
				'progress'
			);

			list( $pages, $newOffset ) = $this->getPagesWithSuggestions( $offset, $this->mBatchSize );
			$this->outputChanneled(
				": titles {$offset}-{$newOffset}. ",
				'progress'
			);
			$offset = $newOffset;

			foreach ( $pages as $page ) {
				$title = Title::newFromText( $page['title'], $page['ns'] );
				if ( !$title ) {
					continue;
				}

				$user = $this->getUserForTitle( $title );
				if ( !$user ) {
					continue;
				}

				$suggestions = $this->getSuggestions( $page['pageid'] );
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
						$this->namespaceInfo->getCanonicalName( 6 ) . ':' . $suggestion['image']
					)
				);
			}

			$this->outputChanneled(
				"Complete.\n",
				'progress'
			);
			$this->loadBalancerFactory->waitForReplication();
		} while ( count( $pages ) >= $this->mBatchSize );

		$numUsers = count( $this->notifiedUserIds );
		$numNotifications = array_sum( $this->notifiedUserIds );
		$numMissing = $offset - $numNotifications;
		$this->outputChanneled(
			"Done. " .
			"Notified {$numUsers} users about {$numNotifications} pages. " .
			"{$numMissing} pages had no available users.",
			'progress'
		);
	}

	/**
	 * @param int $offset
	 * @param int $limit
	 * @return array where 1st element is an array of ['ns' => x, 'title' => y, 'pageid' => z] and 2nd the next offset
	 * @throws MWException
	 */
	private function getPagesWithSuggestions( int $offset, int $limit ): array {
		$request = new FauxRequest( [
			'format' => 'json',
			'action' => 'query',
			'list' => 'search',
			'srsearch' => 'hasrecommendation:image',
			'srnamespace' => 0,
			'srlimit' => $limit,
			'sroffset' => $offset,
			'srinfo' => '',
			'srprop' => '',
			'srinterwiki' => false,
		] );

		$searchUri = getenv( 'MW_API' ) ?: '';
		if ( $searchUri ) {
			// pull data from external API (for use in testing)
			$url = $searchUri . '?' . http_build_query( $request->getQueryValues() );
			$request = MediaWikiServices::getInstance()->getHttpRequestFactory()
				->create( $url, [], __METHOD__ );
			$request->execute();
			$data = $request->getContent();
			$response = json_decode( $data, true ) ?: [];
		} else {
			$context = new DerivativeContext( RequestContext::getMain() );
			$context->setRequest( $request );
			$api = new ApiMain( $context );
			$api->execute();
			$response = $api->getResult()->getResultData( [], [ 'Strip' => 'all' ] );
		}
		$pages = $response['query']['search'] ?? [];

		return [
			$pages,
			$response['continue']['sroffset'] ?? $offset + count( $pages )
		];
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
		if ( !$dbr ) {
			return null;
		}

		// list of users who have already received an image suggestion notification for this page
		$previouslyNotifiedUserIds = $dbr->selectFieldValues(
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

		$notificationOptionWeb = 'echo-subscriptions-web-' . Hooks::EVENT_NAME;
		$notificationOptionEmail = 'echo-subscriptions-email-' . Hooks::EVENT_NAME;
		$notificationDefaultValueWeb = $this->userOptionsLookup->getDefaultOption( $notificationOptionWeb );
		$notificationDefaultValueEmail = $this->userOptionsLookup->getDefaultOption( $notificationOptionEmail );

		$userId = $dbr->selectField(
			[
				'watchlist',
				'user',
				'actor',
				'page',
				'revision',
				'up1' => 'user_properties',
				'up2' => 'user_properties',
			],
			'DISTINCT wl_user',
			array_merge(
				$excludeUserIds ? [ 'wl_user NOT IN (' . $dbr->makeList( $excludeUserIds ) . ')' ] : [],
				[
					'wl_namespace' => $title->getNamespace(),
					'wl_title' => $title->getDBkey(),
					"user_editcount >= {$this->minEditCount}",
					$dbr->makeList( [
						// if notification is enabled by default, we may have no up_property row
						'up1.up_value = 1' . ( $notificationDefaultValueWeb ? ' OR up1.up_value IS NULL' : '' ),
						'up2.up_value = 1' . ( $notificationDefaultValueEmail ? ' OR up2.up_value IS NULL' : '' ),
					], $dbr::LIST_OR ),
				]
			),
			__METHOD__,
			[
				'ORDER BY rev_timestamp DESC',
				'LIMIT' => 1,
			],
			[
				'user' => [ 'INNER JOIN', 'user_id = wl_user' ],
				'actor' => [ 'INNER JOIN', 'actor_user = wl_user' ],
				'page' => [ 'INNER JOIN', [ 'page_namespace = wl_namespace', 'page_title = wl_title' ] ],
				'revision' => [ 'LEFT JOIN', [ 'rev_page = page_id', 'rev_actor' => 'actor_id' ] ],
				'up1' => [ 'LEFT JOIN', [ 'up1.up_user = user_id', 'up1.up_property' => $notificationOptionWeb ] ],
				'up2' => [ 'LEFT JOIN', [ 'up2.up_user = user_id', 'up2.up_property' => $notificationOptionEmail ] ],
			]
		);

		if ( $userId === false ) {
			return null;
		}
		return $this->userFactory->newFromId( $userId );
	}

	protected function createNotification( UserIdentity $user, Title $title, string $mediaUrl ) {
		if ( $this->dryRun ) {
			$this->outputChanneled(
				"Notification: " .
				"user: {$user->getName()} (id: {$user->getId()}), " .
				"title: {$title->getFullText()} (id: {$title->getId()}), " .
				"media: {$mediaUrl}\n",
				'dry-run'
			);
			return false;
		}

		return parent::createNotification( $user, $title, $mediaUrl );
	}
}

$maintClass = SendNotificationsForUnillustratedWatchedTitles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
