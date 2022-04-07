<?php

namespace MediaWiki\Extension\ImageSuggestions;

use EchoEventPresentationModel;
use MediaWiki\MediaWikiServices;

class ImageSuggestionsPresentationModel extends EchoEventPresentationModel {

	public function canRender() {
		return (bool)$this->event->getTitle();
	}

	public function getIconType() {
		return 'image-suggestions-blue';
	}

	public function getHeaderMessage() {
		return $this
			->msg( 'imagesuggestions-notification-message' )
			->params(
				$this->event->getTitle()->getText(),
				$this->getViewingUserForGender()
			);
	}

	public function getPrimaryLink() {
		return [
			'url' => $this->event->getExtra()['media-url'],
			'label' => $this->msg( 'imagesuggestions-notification-link-text-media' ),
		];
	}

	public function getSecondaryLinks() {
		$mainConfig = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'main' );

		$actions = [];
		$actions['article'] = [
			'url' => $this->event->getTitle()->getFullURL(),
			'label' => $this->msg( 'imagesuggestions-notification-link-text-article' ),
			'icon' => 'changes',
			'prioritized' => true,
		];
		$actions['image'] = [
			'url' => $this->event->getExtra()['media-url'],
			'label' => $this->msg( 'imagesuggestions-notification-link-text-media' ),
			// below icon is part of oojs-ui.styles.icons-media module
			'icon' => 'image',
			'prioritized' => true,
		];

		$helpLink = $mainConfig->get( 'ImageSuggestionsHelpLink' );
		if ( $helpLink ) {
			$actions['help'] = [
				'url' => $helpLink,
				'label' => $this->msg( 'imagesuggestions-notification-link-text-help' ),
				// below icon is part of oojs-ui-core.icons module
				'icon' => 'infoFilled',
			];
		}

		return $actions;
	}
}
