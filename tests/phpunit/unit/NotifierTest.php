<?php

namespace MediaWiki\Extension\ImageSuggestions\Tests;

use CirrusSearch\Connection;
use CirrusSearch\Elastica\SearchAfter;
use CirrusSearch\SearchConfig;
use Elastica\Client;
use Elastica\Index;
use Elastica\Result;
use Elastica\ResultSet;
use MediaWiki\Extension\ImageSuggestions\NotificationHelper;
use MediaWiki\Extension\ImageSuggestions\Notifier;
use MediaWiki\Extension\ImageSuggestions\WikiMapHelper;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\StaticUserOptionsLookup;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use MockTitleTrait;
use MultiHttpClient;
use NamespaceInfo;
use Psr\Log\Test\TestLogger;
use User;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\ImageSuggestions\Notifier
 */
class NotifierTest extends MediaWikiUnitTestCase {
	use MockTitleTrait;

	private string $suggestionsUri = 'https://suggestions-uri/%1$s/%2$d';
	private string $instanceOfUri = 'https://instance-of-uri/%1$s/%2$d';
	private array $defaultJobParams = [
		'minConfidence' => 70,
		'minEditCount' => 1,
		'maxNotificationsPerUser' => 2,
		'excludeInstanceOf' => [],
		'maxJobs' => null,
		'dryRun' => false,
		'verbose' => false,
		'jobNumber' => 1,
		'lastPageId' => 0,
		'batchSize' => 100,
		'notifiedUserIds' => [],
		'optedInUserIds' => [],
	];
	private TestLogger $mockLogger;

	/**
	 * @param array $data
	 * 	'searchResults' => array of page ids returned from elasticsearch,
	 *	'idToTitleMap' => [ $pageId => $pageTitle, ... ],
	 * 	'usersByTitle' => [
	 * 		$titleString => [$userId1, $userId2, ...],
	 * 		...
	 * 	],
	 * 'userOptions' => [
	 * 		$userId => ['echo-subscriptions-web-image-suggestions' => true,...],
	 * 		...
	 * ]
	 * 	'suggestionsByPageId' => [
	 * 		$id => [
	 * 			[ 'origin_wiki' => string, 'image' => string, 'confidence' => float ],
	 * 			...
	 * 		]
	 * 		...
	 * 	],
	 * 	'instanceOfByPageId' => [
	 * 		$id => [
	 * 			[ 'instance_of' => ['Q1', 'Q2', ...],
	 * 			...
	 * 		]
	 * 		...
	 * 	],
	 * 	'jobParams' => [],
	 * @return Notifier
	 */
	public function mockNotifier( array $data = [] ): Notifier {
		$multiHttpClient = $this->createMock( MultiHttpClient::class );
		$multiHttpClient->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $data ) {
				$responses = [];
				$pageId =
					trim( substr( trim( $requests[0]['url'] ),
							strrpos( trim( $requests[0]['url'], '/' ), '/' ) ), '/' );
				if ( isset( $data['suggestionsByPageId'] ) ) {
					$responses[] = [
						'response' => [
							'body' => json_encode( [ 'rows' => $data['suggestionsByPageId'][$pageId] ] )
						]
					];
				}
				if ( count( $requests ) === 2 ) {
					$responses[] = [
						'response' => [
							'body' => json_encode( [ 'rows' => $data['instanceOfByPageId'][$pageId] ?? [] ] )
						]
					];
				}
				return $responses;
			}
		);
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromId' )->willReturnCallback( function ( $id ) {
			$user = $this->createMock( User::class );
			$user->method( 'getId' )->willReturn( $id );
			$user->method( 'getName' )->willReturn( (string)$id );
			$user->method( 'isRegistered' )->willReturn( true );
			return $user;
		} );
		$userOptionsLookup = new StaticUserOptionsLookup(
			$data['userOptions'] ?? [],
			[
				'echo-subscriptions-push-image-suggestions' => false,
				'echo-subscriptions-mail-image-suggestions' => false,
				'echo-subscriptions-web-image-suggestions' => false,
			]
		);
		$namespaceInfo = $this->createMock( NamespaceInfo::class );
		$mainDbConnection = $this->createMock( IReadableDatabase::class );
		$mainDbConnection->method( 'selectFieldValues' )->willReturnCallback(
			static function ( $ignore1,
				$ignore2, array $args ) use ( $data ) {
				if ( isset( $data['usersByTitle'] ) ) {
					$excludedUserIds = [];
					foreach ( $args as $key => $value ) {
						if ( preg_match( '/^wl_user NOT IN \(([^\)]*)\)/i', $value, $matches ) ) {
							$excludedUserIds = explode( ',', $matches[1] );
						}
					}
					foreach ( $data['usersByTitle'] as $titleString => $users ) {
						if ( isset( $data['usersByTitle'][$args['wl_title']] ) ) {
							return array_diff(
								$data['usersByTitle'][$args['wl_title']],
								$excludedUserIds
							);
						}
					}
				}
				return [];
			}
		);
		$mainDbConnection->method( 'makeList' )->willReturnCallback(
			static function ( array $array ) {
				return implode( ',', $array );
			}
		);
		$echoDbConnection = $this->createMock( IReadableDatabase::class );
		$echoDbConnection->method( 'selectFieldValues' )->willReturnCallback(
			static function ( $ignore1,
				$ignore2, array $args ) use ( $data ) {
				if ( isset( $data['notifiedUserIdsByArticleId'] ) ) {
					foreach ( $data['notifiedUserIdsByArticleId'] as $articleId => $userIds ) {
						if ( $args['event_page_id'] == $articleId ) {
							return $userIds;
						}
					}
				}
				return [];
			}
		);
		$this->mockLogger = new TestLogger();
		$searchConfig = $this->createMock( SearchConfig::class );
		$searchClient = $this->createMock( Client::class );
		$searchIndex = $this->createMock( Index::class );
		$searchConnection = $this->createMock( Connection::class );
		$searchConnection->method( 'getIndex' )->willReturn( $searchIndex );
		$searchConnection->method( 'getClient' )->willReturn( $searchClient );
		$titleHelper = $this->mockTitleFactory( $data['idToTitleMap'] ?? [] );
		$jobParams = array_merge( $this->defaultJobParams, $data['jobParams'] ?? [] );
		$notificationHelper = $this->createMock( NotificationHelper::class );
		if ( isset( $data['expectedNotificationsCount'] ) ) {
			$notificationHelper->expects( $this->exactly( $data['expectedNotificationsCount'] ) )
				->method( 'createNotification' );
		}
		$wikiMapHelper = $this->createMock( WikiMapHelper::class );
		$wikiMapHelper->method( 'getForeignURL' )->willReturn( 'foreignUrl' );
		$notifier = new Notifier(
			$this->suggestionsUri,
			$this->instanceOfUri,
			$multiHttpClient,
			$userFactory,
			$userOptionsLookup,
			$namespaceInfo,
			$mainDbConnection,
			$echoDbConnection,
			$this->mockLogger,
			$searchConfig,
			$searchConnection,
			$titleHelper,
			$notificationHelper,
			$wikiMapHelper,
			$jobParams
		);
		if ( isset( $data['searchResults'] ) ) {
			$this->setSearchResults( $notifier, $data['searchResults'] );
		}
		return $notifier;
	}

	/**
	 * @param Notifier $notifier
	 * @param array $results
	 */
	private function setSearchResults( Notifier $notifier, array $results = [] ): void {
		// adapted from https://stackoverflow.com/questions/15907249/
		// how-can-i-mock-a-class-that-implements-the-iterator-interface-using-phpunit
		$fakeResultSet = new \stdClass();
		$fakeResultSet->position = 0;
		$fakeResultSet->items = [];
		foreach ( $results as $result ) {
			$resultObject = $this->createMock( Result::class );
			$resultObject->method( 'getId' )->willReturn( $result );
			$fakeResultSet->items[] = $resultObject;
		}

		$resultSet = $this->createMock( ResultSet::class );

		$resultSet->method( 'rewind' )
			->willReturnCallback( static function () use ( $fakeResultSet ) {
				$fakeResultSet->position = 0;
			} );
		$resultSet->method( 'current' )
			->willReturnCallback( static function () use ( $fakeResultSet ) {
				return $fakeResultSet->items[$fakeResultSet->position];
			} );
		$resultSet->method( 'key' )
			->willReturnCallback( static function () use ( $fakeResultSet ) {
				return $fakeResultSet->position;
			} );
		$resultSet->method( 'next' )
			->willReturnCallback( static function () use ( $fakeResultSet ) {
				$fakeResultSet->position ++;
			} );
		$resultSet->method( 'valid' )
			->willReturnCallback( static function () use ( $fakeResultSet ) {
				return isset( $fakeResultSet->items[$fakeResultSet->position] );
			} );
		$resultSet->method( 'count' )
			->willReturnCallback( static function () use ( $fakeResultSet ) {
				return count( $fakeResultSet->items );
			} );

		$searchAfter = $this->createMock( SearchAfter::class );
		$searchAfter->method( 'current' )->willReturn( $resultSet );
		TestingAccessWrapper::newFromObject( $notifier )->searchAfter = $searchAfter;
	}

	private function mockTitleFactory( array $idToTitleMap = [] ): TitleFactory {
		$titleFactory = $this->createMock( TitleFactory::class );
		if ( count( $idToTitleMap ) == 0 ) {
			return $titleFactory;
		}

		$titleObjects = [];
		foreach ( $idToTitleMap as $id => $string ) {
			$mockTitle = $this->makeMockTitle( $string, [ 'id' => $id ] );
			$titleObjects[ $id ] = $mockTitle;
		}
		$titleFactory->method( 'newFromId' )->willReturnCallback(
			static function ( $id ) use ( $titleObjects ) {
				return $titleObjects[$id];
			}
		);
		return $titleFactory;
	}

	public function testSearchAfter() {
		$notifier = $this->mockNotifier();
		$searchAfter = TestingAccessWrapper::newFromObject( $notifier )->searchAfter;
		$this->assertInstanceOf( SearchAfter::class, $searchAfter );
		$initialSearchAfter = TestingAccessWrapper::newFromObject( $searchAfter )
			->initialSearchAfter;
		$this->assertEquals( [], $initialSearchAfter );
	}

	public function testSearchAfterWithLastPageId() {
		$notifier = $this->mockNotifier( [
			'jobParams' => [ 'lastPageId' => 999 ] ,
		] );
		$initialSearchAfter = TestingAccessWrapper::newFromObject(
			TestingAccessWrapper::newFromObject( $notifier )->searchAfter
		)->initialSearchAfter;
		$this->assertEquals( [ 999 ], $initialSearchAfter );
	}

	/**
	 * No title will be found for the search result, therefore there will be no notifications
	 */
	public function testNoTitleFound() {
		$notifier = $this->mockNotifier(
			[
				'searchResults' => [ 999 ]
			]
		);
		$updatedJobParams = $notifier->run();
		$this->assertEquals( 999, $updatedJobParams['lastPageId'] );
		$this->assertEquals( [], $updatedJobParams['notifiedUserIds'] );
		$this->assertEquals( [], $updatedJobParams['optedInUserIds'] );
		$this->assertTrue( $this->mockLogger->hasDebugThatContains( 'No title found for 999' ) );
	}

	/**
	 * Title found for the search result, but no user, therefore there will be no notifications
	 */
	public function testNoUserFound() {
		$idToTitleMap = [ 999 => 'Title_1' ];
		$notifier = $this->mockNotifier(
			[
				'searchResults' => array_keys( $idToTitleMap ),
				'idToTitleMap' => $idToTitleMap,
			]
		);
		$updatedJobParams = $notifier->run();
		$this->assertEquals( 999, $updatedJobParams['lastPageId'] );
		$this->assertEquals( [], $updatedJobParams['notifiedUserIds'] );
		$this->assertEquals( [], $updatedJobParams['optedInUserIds'] );
	}

	/**
	 * Title found for the search result, user found but not opted-in, therefore there will be no
	 * notifications
	 */
	public function testNoOptedInUserFound() {
		$idToTitleMap = [ 999 => 'Title_1' ];
		$notifier = $this->mockNotifier(
			[
				'searchResults' => array_keys( $idToTitleMap ),
				'idToTitleMap' => $idToTitleMap,
				'usersByTitle' => [ 'Title_1' => [ 1 ] ],
			]
		);
		$updatedJobParams = $notifier->run();
		$this->assertEquals( [ 1 => false ], $updatedJobParams['optedInUserIds'] );
		$this->assertEquals( [], $updatedJobParams['notifiedUserIds'] );
		$this->assertTrue( $this->mockLogger->hasDebugThatContains( 'No user found for Title_1' ) );
	}

	/**
	 * Title found for the search result, opted-in user found, no response from suggestions api
	 */
	public function testNoSuggestionsFound() {
		$idToTitleMap = [ 999 => 'Title_1' ];
		$notifier = $this->mockNotifier(
			[
				'searchResults' => array_keys( $idToTitleMap ),
				'idToTitleMap' => $idToTitleMap,
				'usersByTitle' => [
					'Title_1' => [ 1 ],
				],
				'userOptions' => [
					1 => [ 'echo-subscriptions-push-image-suggestions' => true ]
				]
			]
		);
		$updatedJobParams = $notifier->run();
		$this->assertEquals( [ 1 => true ], $updatedJobParams['optedInUserIds'] );
		$this->assertTrue(
			$this->mockLogger->hasDebugThatContains( 'No suggestions found for 999' )
		);
	}

	/**
	 * Title found for the search result, the only user for the title has already been notified
	 */
	public function testUserAlreadyNotified() {
		$idToTitleMap = [ 999 => 'Title_1' ];
		$notifier = $this->mockNotifier(
			[
				'searchResults' => array_keys( $idToTitleMap ),
				'idToTitleMap' => $idToTitleMap,
				'usersByTitle' => [
					'Title_1' => [ 1 ],
				],
				'userOptions' => [
					1 => [ 'echo-subscriptions-push-image-suggestions' => true ]
				],
				'notifiedUserIdsByArticleId' => [ 999 => [ 1 ] ],
			]
		);
		$updatedJobParams = $notifier->run();
		$this->assertEquals( [], $updatedJobParams['notifiedUserIds'] );
		$this->assertTrue( $this->mockLogger->hasDebugThatContains( 'No user found for Title_1' ) );
	}

	/**
	 * Title found for the search result, opted-in user found but has already been notified > max
	 */
	public function testOnlyFoundUserWithOverMaxNotifs() {
		$idToTitleMap = [ 999 => 'Title_1' ];
		$notifier = $this->mockNotifier(
			[
				'searchResults' => array_keys( $idToTitleMap ),
				'idToTitleMap' => $idToTitleMap,
				'usersByTitle' => [
					'Title_1' => [ 1 ],
				],
				'userOptions' => [ 1 => [ 'echo-subscriptions-push-image-suggestions' => true ] ],
				'jobParams' => [
					'notifiedUserIds' =>
						[ 1 => $this->defaultJobParams['maxNotificationsPerUser'] ]
				],
			]
		);
		$updatedJobParams = $notifier->run();
		$this->assertEquals(
			[ 1 => $this->defaultJobParams['maxNotificationsPerUser'] ],
			$updatedJobParams['notifiedUserIds']
		);
		$this->assertTrue( $this->mockLogger->hasDebugThatContains( 'No user found for Title_1' ) );
	}

	/**
	 * Title found for the search result, we know they're already opted-in from the job params, no
	 * response from suggestions api
	 */
	public function testOptedInFromJobParams() {
		$idToTitleMap = [ 999 => 'Title_1' ];
		$notifier = $this->mockNotifier(
			[
				'searchResults' => array_keys( $idToTitleMap ),
				'idToTitleMap' => $idToTitleMap,
				'usersByTitle' => [
					'Title_1' => [ 1 ]
				],
				'jobParams' => [ 'optedInUserIds' => [ 1 => true ] ],
			]
		);
		$updatedJobParams = $notifier->run();
		$this->assertEquals( [ 1 => true ], $updatedJobParams['optedInUserIds'] );
		$this->assertTrue(
			$this->mockLogger->hasDebugThatContains( 'No suggestions found for 999' )
		);
	}

	public function testNotifications() {
		$idToTitleMap = [
			999 => 'Title_1',
			888 => 'Title_2',
			777 => 'Title_3',
			666 => 'Title_4',
			555 => 'Title_5',
		];
		$notifier = $this->mockNotifier(
			[
				'searchResults' => array_keys( $idToTitleMap ),
				'idToTitleMap' => $idToTitleMap,
				'usersByTitle' => [
					'Title_1' => [ 1, 2 ],
					'Title_2' => [ 1, 3 ],
					'Title_3' => [ 1, 4, 5 ],
					'Title_4' => [ 6 ],
					'Title_5' => [ 7 ],
				],
				'userOptions' => [
					1 => [ 'echo-subscriptions-push-image-suggestions' => true ],
					2 => [ 'echo-subscriptions-mail-image-suggestions' => true ],
					3 => [ 'echo-subscriptions-web-image-suggestions' => true ],
					5 => [ 'echo-subscriptions-push-image-suggestions' => true ],
					6 => [ 'echo-subscriptions-push-image-suggestions' => true ],
				],
				'suggestionsByPageId' => [
					// Two images, send one notification to one user (user 1)
					999 => [
						[ 'origin_wiki' => 'enwiki', 'image' => 'Image_999_1', 'confidence' => 70 ],
						[ 'origin_wiki' => 'enwiki', 'image' => 'Image_999_2', 'confidence' => 80 ],
					],
					// One image, send one notification to one user (user 1)
					888 => [
						[ 'origin_wiki' => 'enwiki', 'image' => 'Image_888_1', 'confidence' => 80 ],
					],
					// One image, send one notification to one user
					// Will be user 5, as user 4 is not opted in and user 1 is > max notifications
					777 => [
						[ 'origin_wiki' => 'enwiki', 'image' => 'Image_777_1', 'confidence' => 80 ],
					],
					// no notification as confidence is too low
					666 => [
						[ 'origin_wiki' => 'enwiki', 'image' => 'Image_666_1', 'confidence' => 10 ],
					],
					// no opted-in user for this title, so no notification
					555 => [
						[ 'origin_wiki' => 'enwiki', 'image' => 'Image_555_1', 'confidence' => 90 ],
					],
				],
				'expectedNotificationsCount' => 3,
			]
		);
		$updatedJobParams = $notifier->run();

		$this->assertEquals(
			[ 1 => true, 4 => false, 5 => true, 6 => true, 7 => false ],
			$updatedJobParams['optedInUserIds']
		);
		$this->assertEquals(
			[ 1 => 2, 5 => 1 ],
			$updatedJobParams['notifiedUserIds']
		);
		$this->assertTrue(
			$this->mockLogger->hasInfoThatContains(
				"Finished job. " .
				"In total have notified 2 users about 3 pages. " .
				"Notifications not sent for 2 pages as they had no available users " .
				"or the suggestions were excluded or didn't meet the confidence threshold."
			)
		);
	}

	public function testExcludeInstanceOf() {
		$idToTitleMap = [
			999 => 'Title_1',
			888 => 'Title_2',
		];
		$notifier = $this->mockNotifier(
			[
				'searchResults' => array_keys( $idToTitleMap ),
				'idToTitleMap' => $idToTitleMap,
				'usersByTitle' => [
					'Title_1' => [ 1 ],
					'Title_2' => [ 1 ],
				],
				'userOptions' => [
					1 => [ 'echo-subscriptions-push-image-suggestions' => true ]
				],
				'suggestionsByPageId' => [
					999 => [
						[ 'origin_wiki' => 'enwiki', 'image' => 'Image_999_1', 'confidence' => 70 ],
					],
					// no notification, exluded by instanceOf
					888 => [
						[ 'origin_wiki' => 'enwiki', 'image' => 'Image_888_1', 'confidence' => 80 ],
					],
				],
				'instanceOfByPageId' => [
					888 => [
						[ 'instance_of' => [ 'Q1' ] ],
					],
				],
				'jobParams' => [ 'excludeInstanceOf' => [ 'Q1' ] ],
				'expectedNotificationsCount' => 1,
			]
		);
		$updatedJobParams = $notifier->run();
		$this->assertEquals(
			[ 1 => 1 ],
			$updatedJobParams['notifiedUserIds']
		);
		$this->assertTrue(
			$this->mockLogger->hasInfoThatContains(
				"Finished job. " .
				"In total have notified 1 users about 1 pages. " .
				"Notifications not sent for 1 pages as they had no available users " .
				"or the suggestions were excluded or didn't meet the confidence threshold."
			)
		);
	}
}
