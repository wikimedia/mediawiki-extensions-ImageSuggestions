<?php

namespace MediaWiki\Extension\ImageSuggestions;

/**
 * ImageSuggestions Echo test notification helper
 *
 * @file
 * @ingroup Extensions
 * @license MIT
 */

use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

class ImageSuggestionsEchoTestHelper {
	/**
	 * Notify the user if they haven't already been notified on this wiki
	 *
	 * @param UserIdentity $user
	 * @param Title $title
	 * @param string $imageurl
	 */
	public static function send( UserIdentity $user, Title $title, $imageurl ) {
		$type = 'image-suggestions';

		Event::create( [
			'type' => $type,
			'title' => $title,
			'extra' => [
				'imageurl' => $imageurl,
			],
			'agent' => $user,
		] );
	}
}
