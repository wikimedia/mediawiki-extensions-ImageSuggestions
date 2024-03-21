<?php

namespace MediaWiki\Extension\ImageSuggestions\Tests;

use MediaWiki\Extension\ImageSuggestions\Hooks;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Output\OutputPage;
use MediaWikiUnitTestCase;
use MockTitleTrait;
use SkinTemplate;

/**
 * @covers \MediaWiki\Extension\ImageSuggestions\Hooks
 */
class HooksTest extends MediaWikiUnitTestCase {
	use MockTitleTrait;

	public function testOnBeforePageDisplay() {
		$skin = new SkinTemplate();
		$output = $this->createMock( OutputPage::class );
		$output->expects( $this->once() )->method( 'addModules' );
		( new Hooks )->onBeforePageDisplay( $output, $skin );
	}

	public function testOnBeforeCreateEchoEvent() {
		$notifications = [];
		$notificationCategories = [];
		$icons = [];
		( new Hooks )->onBeforeCreateEchoEvent( $notifications, $notificationCategories, $icons );
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
		( new Hooks )->onEchoGetBundleRules( $event, $bundleString );
		$this->assertEquals( Hooks::EVENT_NAME . '-' . NS_MAIN . '-Test_title', $bundleString );
	}
}
