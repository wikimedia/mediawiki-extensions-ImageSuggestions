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

use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\Extension\Notifications\Hooks\EchoGetBundleRulesHook;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use Skin;

class Hooks implements
	BeforeCreateEchoEventHook,
	BeforePageDisplayHook,
	EchoGetBundleRulesHook
{
	public const EVENT_CATEGORY = 'image-suggestions';
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

	/**
	 * Add Image Suggestions events to Echo
	 *
	 * @param array &$notifications array of Echo notifications
	 * @param array &$notificationCategories array of Echo notification categories
	 * @param array &$icons array of icon details
	 */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	) {
		// Define the category this event belongs to
		// (this will appear in Special:Preferences)
		$notificationCategories[static::EVENT_CATEGORY] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-image-suggestions',
		];

		$notifications[static::EVENT_NAME] = [
			'category' => static::EVENT_CATEGORY,
			'group' => 'positive',
			'section' => 'message',
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'canNotifyAgent' => true,
			'presentation-model' => ImageSuggestionsPresentationModel::class,
			'bundle' => [
				'web' => true,
				'email' => true,
				'expandable' => true,
			]
		];

		// Define the icon to use for this notification
		$icons[ 'image-suggestions-blue' ] = [
			'path' => 'ImageSuggestions/modules/ImageSuggestions-placeholder-icon-blue.svg'
		];
	}

	public function onEchoGetBundleRules( Event $event, string &$bundleString ) {
		if ( $event->getType() === static::EVENT_NAME ) {
			$bundleString = static::EVENT_NAME . '-' .
				$event->getTitle()->getNamespace() . '-' . $event->getTitle()->getDBkey();
		}
		return true;
	}
}
