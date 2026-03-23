<?php

namespace MediaWiki\Extension\ImageSuggestions\Tests;

use MediaWiki\Extension\ImageSuggestions\NotificationHelper;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use MockTitleTrait;
use TestLogger;

class NotificationHelperTest extends MediaWikiUnitTestCase {
	use MockTitleTrait;

	/**
	 * @covers \MediaWiki\Extension\ImageSuggestions\NotificationHelper
	 */
	public function testCreateNotification() {
		$userId = 999;
		$userName = 'test user';
		$titleId = 888;
		$titleText = 'test title';
		$mediaUrl = 'http://commons/File:Image_1.jpg';
		$sectionHeading = 'Section_X';

		$mockLogger = new TestLogger( true );
		$helper = new NotificationHelper();
		$notification = $helper->createNotification(
			new UserIdentityValue( $userId, $userName ),
			$this->makeMockTitle( $titleText, [ 'id' => $titleId ] ),
			$mediaUrl,
			$sectionHeading,
			$mockLogger,
			true
		);
		$this->assertNull( $notification );
		$this->assertTrue( array_any( $mockLogger->getBuffer(), static fn ( $buffer ) => str_contains( $buffer[1],
			"Notification: " .
			"user: {userName} (id: {userId}), " .
			"title: {titleText} (id: {titleId}), " .
			"media-url: {mediaUrl}, " .
			"section-heading: {sectionHeading}"
		) ) );
	}
}
