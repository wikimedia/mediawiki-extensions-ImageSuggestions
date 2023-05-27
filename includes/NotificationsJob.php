<?php

namespace MediaWiki\Extension\ImageSuggestions;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use GenericParameterJob;
use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWEchoDbFactory;
use Psr\Log\LoggerInterface;

class NotificationsJob extends Job implements GenericParameterJob {

	public function __construct(
		array $params
	) {
		parent::__construct( 'ImageSuggestionsNotifications', $params );
	}

	/**
	 * Invokes this job, either immediately, or by adding it to the
	 * jobs queue. The latter is the preferred method for automated
	 * runs in production, but the former is useful for one-of manual
	 * test runs.
	 *
	 * @param bool $queue
	 * @return void
	 */
	public function invoke( bool $queue = true ): void {
		if ( $queue ) {
			$jobQueueGroup = $this->getServices()->getJobQueueGroup();
			$jobQueueGroup->push( $this );
		} else {
			$this->run();
		}
	}

	public function run(): bool {
		$logger = LoggerFactory::getInstance( 'ImageSuggestions' );
		$notifier = $this->createNotifier( $logger, $this->params );
		if ( !$notifier ) {
			return false;
		}
		$updatedJobParams = $notifier->run();
		if ( $updatedJobParams ) {
			$this->invokeNext( $logger, $updatedJobParams );
		}

		return true;
	}

	protected function getServices(): MediaWikiServices {
		return MediaWikiServices::getInstance();
	}

	private function createNotifier( LoggerInterface $logger, array $params ): ?Notifier {
		$services = $this->getServices();
		$config = $services->getMainConfig();
		$searchConfig = $services->getConfigFactory()->makeConfig( 'CirrusSearch' );
		if ( !( $searchConfig instanceof SearchConfig ) ) {
			$logger->error( 'Wrong config type returned from makeConfig()' );
			return null;
		}
		$dbr = $services->getDBLoadBalancerFactory()->getReplicaDatabase();
		$dbrEcho = MWEchoDbFactory::newFromDefault()->getEchoDb( DB_REPLICA );

		return new Notifier(
			$config->get( 'ImageSuggestionsSuggestionsApi' ),
			$config->get( 'ImageSuggestionsInstanceOfApi' ),
			$services->getHttpRequestFactory()->createMultiClient(),
			$services->getUserFactory(),
			$services->getUserOptionsLookup(),
			$services->getNamespaceInfo(),
			$dbr,
			$dbrEcho,
			$logger,
			$searchConfig,
			Connection::getPool( $searchConfig ),
			$services->getTitleFactory(),
			new NotificationHelper(),
			new WikiMapHelper(),
			$params
		);
	}

	private function invokeNext( LoggerInterface $logger, array $params ): void {
		if ( $params['maxJobs'] > 0 && $params['jobNumber'] >= $params['maxJobs'] ) {
			return;
		}

		$logger->info( 'Queuing next batch' );
		$params['jobNumber'] += 1;
		$job = new NotificationsJob( $params );
		$job->invoke( $params['queue'] );
	}
}
