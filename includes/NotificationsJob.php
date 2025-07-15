<?php

namespace MediaWiki\Extension\ImageSuggestions;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Extension\Notifications\DbFactory;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\LBFactory;

class NotificationsJob extends Job {

	public function __construct(
		array $params,
		private readonly ConfigFactory $configFactory,
		private readonly LBFactory $lbFactory,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly Config $config,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory,
		private readonly UserOptionsLookup $userOptionsLookup,
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
			$this->jobQueueGroup->push( $this );
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

	private function createNotifier( LoggerInterface $logger, array $params ): ?Notifier {
		$searchConfig = $this->configFactory->makeConfig( 'CirrusSearch' );
		if ( !( $searchConfig instanceof SearchConfig ) ) {
			$logger->error( 'Wrong config type returned from makeConfig()' );
			return null;
		}
		$dbr = $this->lbFactory->getReplicaDatabase();
		$dbrEcho = DbFactory::newFromDefault()->getEchoDb( DB_REPLICA );

		return new Notifier(
			$this->config->get( 'ImageSuggestionsSuggestionsApi' ),
			$this->config->get( 'ImageSuggestionsInstanceOfApi' ),
			$this->httpRequestFactory->createMultiClient(),
			$this->userFactory,
			$this->userOptionsLookup,
			$this->namespaceInfo,
			$dbr,
			$dbrEcho,
			$logger,
			$searchConfig,
			Connection::getPool( $searchConfig ),
			$this->titleFactory,
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
		$job = new NotificationsJob(
			$params,
			$this->configFactory,
			$this->lbFactory,
			$this->httpRequestFactory,
			$this->jobQueueGroup,
			$this->config,
			$this->namespaceInfo,
			$this->titleFactory,
			$this->userFactory,
			$this->userOptionsLookup
		);
		$job->invoke( $params['queue'] );
	}
}
