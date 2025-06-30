<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\ImageSuggestions;

use MediaWiki\Extension\Notifications\Hooks\EchoGetBundleRulesHook;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\Skin;

class Hooks implements
	BeforePageDisplayHook,
	EchoGetBundleRulesHook
{
	public const EVENT_NAME = 'image-suggestions';

	/**
	 * @param OutputPage $output
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $output, $skin ): void {
		$output->addModules( [
			'oojs-ui.styles.icons-media',
			'oojs-ui-core.icons'
		] );
	}

	/** @inheritDoc */
	public function onEchoGetBundleRules( Event $event, string &$bundleString ) {
		if ( $event->getType() === static::EVENT_NAME ) {
			$bundleString = static::EVENT_NAME . '-' .
				$event->getTitle()->getNamespace() . '-' . $event->getTitle()->getDBkey();
		}
		return true;
	}
}
