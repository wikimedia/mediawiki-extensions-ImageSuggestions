<?php

namespace MediaWiki\Extension\ImageSuggestions;

/**
 * ImageSuggestions Echo test notification helper
 *
 * @file
 * @ingroup Extensions
 * @license MIT
 */

use EchoEvent;
use MediaWiki\User\UserIdentity;
use Title;

class ImageSuggestionsEchoTestHelper {
	/**
	 * Notify the user if they haven't already been notified on this wiki
	 *
	 * @param UserIdentity $user
	 * @param Title $title
	 * @param string $imageurl
	 * @return bool
	 */
	public static function send( UserIdentity $user, Title $title, $imageurl ) {
		$type = 'image-suggestions';

		EchoEvent::create( [
			'type' => $type,
			'title' => $title,
			'extra' => [
				'imageurl' => $imageurl,
			],
			'agent' => $user,
		] );
	}
}
