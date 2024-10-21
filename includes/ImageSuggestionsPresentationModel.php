<?php

namespace MediaWiki\Extension\ImageSuggestions;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;

class ImageSuggestionsPresentationModel extends EchoEventPresentationModel {

	public function canRender() {
		return (bool)$this->event->getTitle();
	}

	public function getIconType() {
		return 'image-suggestions-blue';
	}

	public function getHeaderMessage() {
		if ( $this->isBundled() ) {
			return $this->msg( 'imagesuggestions-notification-message-bundle' )
				->params(
					$this->event->getTitle()->getText(),
					$this->getViewingUserForGender()
				);
		}
		if ( $this->isSectionSuggestion() ) {
			return $this->msg( 'imagesuggestions-notification-message-section' )
				->params(
					// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
					$this->getSectionHeadingForDisplay(),
					$this->event->getTitle()->getText(),
					$this->getViewingUserForGender()
				);
		}
		return $this->msg( 'imagesuggestions-notification-message-article' )
			->params(
				$this->event->getTitle()->getText(),
				$this->getViewingUserForGender()
			);
	}

	public function getPrimaryLink(): array {
		if ( !$this->isBundled() ) {
			return [
				'url' => $this->event->getExtra()['media-url'],
				'label' => $this->msg( 'imagesuggestions-notification-link-text-image' ),
			];
		}
		return [];
	}

	private function isSectionSuggestion(): bool {
		return $this->getSectionHeading() !== null;
	}

	private function getSectionHeading(): ?string {
		return $this->event->getExtra()['section-heading'] ?? null;
	}

	private function getSectionHeadingForDisplay(): ?string {
		$heading = $this->getSectionHeading();
		if ( $heading === null ) {
			return null;
		}
		return str_replace( '_', ' ', $heading );
	}

	private function getSuggestionTargetUrl(): string {
		$url = $this->event->getTitle()->getFullURL();
		if ( $this->isSectionSuggestion() ) {
			$url .= '#' . $this->getSectionHeading();
		}
		return $url;
	}

	public function getCompactHeaderMessage(): Message {
		if ( $this->isSectionSuggestion() ) {
			$msg = $this->msg( 'imagesuggestions-notification-bundle-section' );
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$msg->params( $this->getSectionHeadingForDisplay() );
			return $msg;
		}
		return $this->msg( 'imagesuggestions-notification-bundle-article' );
	}

	public function getSecondaryLinks() {
		$actions = [];
		if ( !$this->isBundled() ) {
			$actions['article'] = [
				'url' => $this->getSuggestionTargetUrl(),
				'label' => $this->isSectionSuggestion() ?
					$this->msg( 'imagesuggestions-notification-link-text-section' ) :
					$this->msg( 'imagesuggestions-notification-link-text-article' ),
				'icon' => 'changes',
				'prioritized' => true,
			];

			$actions['image'] = [
				'url' => $this->event->getExtra()['media-url'],
				'label' => $this->msg( 'imagesuggestions-notification-link-text-image' ),
				// below icon is part of oojs-ui.styles.icons-media module
				'icon' => 'image',
				'prioritized' => true,
			];

			$mainConfig = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'main' );
			$helpLink = $mainConfig->get( 'ImageSuggestionsHelpLink' );
			if ( $helpLink ) {
				$actions['help'] = [
					'url' => $helpLink,
					'label' => $this->msg( 'imagesuggestions-notification-link-text-help' ),
					// below icon is part of oojs-ui-core.icons module
					'icon' => 'infoFilled',
				];
			}
		}

		return $actions;
	}
}
