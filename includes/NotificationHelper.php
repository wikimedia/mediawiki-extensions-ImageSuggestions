<?php

namespace MediaWiki\Extension\ImageSuggestions;

use EchoEvent;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;

/**
 * Wrapper for EchoEvent::create() with logging, to facilitate testing of Notifier.php
 */
class NotificationHelper {
	public function createNotification(
		UserIdentity $user,
		Title $title,
		string $mediaUrl,
		?string $sectionHeading = null,
		LoggerInterface $logger = null,
		bool $noop = false
	): ?EchoEvent {
		if ( $logger ) {
			$logger->info(
				"Notification: " .
				"user: {userName} (id: {userId}), " .
				"title: {titleText} (id: {titleId}), " .
				"media-url: {mediaUrl}, " .
				"section-heading: {sectionHeading} ",
				[
					'userName' => $user->getName(),
					'userId' => $user->getId(),
					'titleText' => $title->getText(),
					'titleId' => $title->getId(),
					'mediaUrl' => $mediaUrl,
					'sectionHeading' => $sectionHeading ?? 'none',
				]
			);
		}

		if ( $noop ) {
			return null;
		}

		// @codeCoverageIgnoreStart
		return EchoEvent::create( [
			'type' => Hooks::EVENT_NAME,
			'title' => $title,
			'agent' => $user,
			'extra' => [
				'media-url' => $mediaUrl,
				'section-heading' => $sectionHeading,
			],
		] );
		// @codeCoverageIgnoreEnd
	}
}
