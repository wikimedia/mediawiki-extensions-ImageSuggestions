<?php

namespace MediaWiki\Extension\ImageSuggestions;

use MediaWiki\WikiMap\WikiMap;

/**
 * Wrapper for WikiMap::getForeignURL() to facilitate testing of Notifier.php
 */
class WikiMapHelper {
	/**
	 * @codeCoverageIgnore
	 * @return string|false
	 */
	public function getForeignURL( string $wikiID, string $page, ?string $fragmentId = null ) {
		return WikiMap::getForeignURL( $wikiID, $page, $fragmentId );
	}
}
