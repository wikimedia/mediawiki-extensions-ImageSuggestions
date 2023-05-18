<?php

namespace MediaWiki\Extension\ImageSuggestions\Tests;

use MediaWiki\Extension\ImageSuggestions\NotificationHelper;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use MockTitleTrait;
use Psr\Log\Test\TestLogger;

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

		$mockLogger = new TestLogger();
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
		$this->assertTrue( $mockLogger->hasInfoThatContains(
			"Notification: " .
			"user: {userName} (id: {userId}), " .
			"title: {titleText} (id: {titleId}), " .
			"media-url: {mediaUrl}, " .
			"section-heading: {sectionHeading}"
		) );
	}
}
