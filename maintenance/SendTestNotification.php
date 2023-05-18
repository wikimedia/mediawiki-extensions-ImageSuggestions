<?php

namespace MediaWiki\Extension\ImageSuggestions\Maintenance;

use Maintenance;
use MediaWiki\Extension\ImageSuggestions\NotificationHelper;
use MediaWiki\MediaWikiServices;
use Title;

class SendTestNotification extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'ImageSuggestions' );
		$this->requireExtension( 'Echo' );

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
		$this->addOption(
			'section-heading',
			"Section of the article the suggestion is for",
			false,
			true
		);
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();

		$notificationHelper = new NotificationHelper();
		$success = $notificationHelper->createNotification(
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$services->getUserFactory()->newFromName( $this->getOption( 'agent' ) ),
			Title::newFromText( $this->getOption( 'title' ) ),
			$this->getOption( 'media-url' ),
			$this->getOption( 'section-heading' )
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
