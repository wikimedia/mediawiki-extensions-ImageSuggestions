<?php

namespace MediaWiki\Extension\ImageSuggestions\Tests;

use MediaWiki\Extension\ImageSuggestions\Hooks;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWikiUnitTestCase;
use MockTitleTrait;
use ParserOutput;

/**
 * @covers \MediaWiki\Extension\ImageSuggestions\Hooks
 */
class HooksTest extends MediaWikiUnitTestCase {
	use MockTitleTrait;

	public function testOnBeforePageDisplay() {
		$parserOutput = $this->createMock( ParserOutput::class );
		$parserOutput->expects( $this->once() )->method( 'addModules' );
		Hooks::onBeforePageDisplay( $parserOutput );
	}

	public function testOnBeforeCreateEchoEvent() {
		$notifications = [];
		$notificationCategories = [];
		$icons = [];
		Hooks::onBeforeCreateEchoEvent( $notifications, $notificationCategories, $icons );
		$this->assertArrayHasKey( Hooks::EVENT_CATEGORY, $notificationCategories );
		$this->assertArrayHasKey( Hooks::EVENT_NAME, $notifications );
		$this->assertArrayHasKey( 'image-suggestions-blue', $icons );
	}

	public function testOnEchoGetBundleRules() {
		$title = $this->makeMockTitle( 'Test_title', [ 'id' => 1 ] );
		$event = $this->createMock( Event::class );
		$event->expects( $this->once() )->method( 'getType' )->willReturn( Hooks::EVENT_NAME );
		$event->method( 'getTitle' )->willReturn( $title );
		$bundleString = '';
		Hooks::onEchoGetBundleRules( $event, $bundleString );
		$this->assertEquals( Hooks::EVENT_NAME . '-' . NS_MAIN . '-Test_title', $bundleString );
	}
}
