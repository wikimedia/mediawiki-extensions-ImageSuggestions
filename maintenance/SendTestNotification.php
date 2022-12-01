<?php

namespace MediaWiki\Extension\ImageSuggestions\Maintenance;

use MediaWiki\MediaWikiServices;
use Title;

require_once __DIR__ . '/AbstractNotifications.php';

class SendTestNotification extends AbstractNotifications {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generate a test notification for unillustrated watchlisted pages' );

		$this->addOption(
			'agent',
			"User to send test notification to",
			true,
			true
		);
		$this->addOption(
			'title',
			"Title of the page for which to send a suggestion",
			true,
			true
		);
		$this->addOption(
			'media-url',
			"URL for image being suggested for",
			true,
			true
		);
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();

		$success = $this->createNotification(
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$services->getUserFactory()->newFromName( $this->getOption( 'agent' ) ),
			Title::newFromText( $this->getOption( 'title' ) ),
			$this->getOption( 'media-url' )
		);

		if ( $success ) {
			$this->output( "Notification sent\n" );
		} else {
			$this->output( "Notification not sent\n" );
		}
	}
}

$maintClass = SendTestNotification::class;
require_once RUN_MAINTENANCE_IF_MAIN;
