<?php

namespace MediaWiki\Extension\ImageSuggestions\Maintenance;

use EchoEvent;
use Maintenance;
use MediaWiki\Extension\ImageSuggestions\Hooks;
use MediaWiki\User\UserIdentity;
use Title;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

abstract class AbstractNotifications extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'ImageSuggestions' );
		$this->requireExtension( 'Echo' );
	}

	/**
	 * @param UserIdentity $user
	 * @param Title $title
	 * @param string $mediaUrl
	 * @return EchoEvent|false
	 * @throws \MWException
	 */
	protected function createNotification( UserIdentity $user, Title $title, string $mediaUrl ) {
		return EchoEvent::create( [
			'type' => Hooks::EVENT_NAME,
			'title' => $title,
			'agent' => $user,
			'extra' => [
				'media-url' => $mediaUrl,
			],
		] );
	}
}
