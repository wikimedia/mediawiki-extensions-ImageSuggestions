<?php

namespace MediaWiki\Extension\ImageSuggestions\Tests;

use MediaWiki\Extension\ImageSuggestions\Hooks;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\SkinTemplate;
use MediaWikiUnitTestCase;
use MockTitleTrait;

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
