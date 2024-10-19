<?php

namespace MediaWiki\Extension\ImageSuggestions\Maintenance;

use MediaWiki\Extension\ImageSuggestions\NotificationsJob;
use MediaWiki\Logger\ConsoleSpi;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Maintenance\Maintenance;
use Psr\Log\LogLevel;

class SendNotificationsForUnillustratedWatchedTitles extends Maintenance {
	private array $defaultParams = [
		'minEditCount' => 1,
		'minConfidence' => 0,
		'minConfidenceSection' => 0,
		'maxNotificationsPerUser' => 2,
		'excludeInstanceOf' => [],
		'maxJobs' => -1,
		'dryRun' => false,
		'verbose' => false,
		'queue' => false,
		'batchSize' => 100,
		'jobNumber' => 1,
		'lastPageId' => 0,
		'notifiedUserIds' => [],
		'optedInUserIds' => [],
	];

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'ImageSuggestions' );
		$this->requireExtension( 'Echo' );
		$this->requireExtension( 'CirrusSearch' );

		$this->addDescription( 'Generate notifications for unillustrated watchlisted pages' );

		$this->addOption(
			'min-edit-count',
			"Minimum edit count for users to receive notification, default: " . $this->defaultParams['minEditCount'],
			false,
			true
		);
		$this->addOption(
			'min-confidence',
			// @codingStandardsIgnoreStart
			"Minimum confidence score (0-100) required to send notification for an article-level suggestion, default: " .  $this->defaultParams['minConfidence'],
			// @codingStandardsIgnoreEnd
			false,
			true
		);
		$this->addOption(
			'min-confidence-section',
			// @codingStandardsIgnoreStart
			"Minimum confidence score (0-100) required to send notification for a section-level suggestion, default: " .  $this->defaultParams['minConfidenceSection'],
			// @codingStandardsIgnoreEnd
			false,
			true
		);
		$this->addOption(
			'max-notifications-per-user',
			// @codingStandardsIgnoreStart
			"Maximum amount of notifications to create per user, per run of this script, default: " . $this->defaultParams['maxNotificationsPerUser'],
			// @codingStandardsIgnoreEnd
			false,
			true
		);
		$defaultExcludeInstanceOf =
			$this->defaultParams['excludeInstanceOf'] ?
				implode( ', ', $this->defaultParams['excludeInstanceOf'] ) :
				'<none>';
		$this->addOption(
			'exclude-instance-of',
			// @codingStandardsIgnoreStart
			"Item Q-id of which the page's associated entity must not be an instance of, default: $defaultExcludeInstanceOf",
			// @codingStandardsIgnoreEnd
			false,
			true,
			false,
			true
		);
		$this->addOption(
			'max-jobs',
			// @codingStandardsIgnoreStart
			"Maximum amount of jobs that will be run (used for debugging), default: null",
			// @codingStandardsIgnoreEnd
			false,
			true
		);
		$this->addOption(
			'dry-run',
			'Prevent notifications from being sent'
		);
		$this->addOption(
			'verbose',
			'Output details of each notification being sent'
		);
		$this->addOption(
			'queue',
			'Run batches through job queue'
		);

		$this->setBatchSize( 100 );
	}

	public function execute() {
		$params = $this->getJobParams();

		// if jobs are going to be executed immediately instead of going to
		// the queue, then capture output in console so we can follow along
		if ( !$params['queue'] ) {
			LoggerFactory::registerProvider( new ConsoleSpi( [
				'channels' => [
					'ImageSuggestions' => LogLevel::INFO,
				],
				// @todo don't forward for now; prod monolog config
				//   unconditionally expects other providers to have a
				//   `getHandler` methods, which is not true for ConsoleSpi;
				//   since this is only for test runs, this is fine for now
				// 'forwardTo' => LoggerFactory::getProvider(),
			] ) );
		}

		$job = new NotificationsJob( $params );
		$job->invoke( $params['queue'] );
	}

	public function getJobParams(): array {
		return [
			'minEditCount' => (int)$this->getOption(
				'min-edit-count',
				$this->defaultParams['minEditCount']
			),
			'minConfidence' => (int)$this->getOption(
				'min-confidence',
				$this->defaultParams['minConfidence']
			),
			'minConfidenceSection' => (int)$this->getOption(
				'min-confidence-section',
				$this->defaultParams['minConfidenceSection']
			),
			'maxNotificationsPerUser' => (int)$this->getOption(
				'max-notifications-per-user',
				$this->defaultParams['maxNotificationsPerUser']
			),
			'excludeInstanceOf' => (array)$this->getOption(
				'exclude-instance-of',
				$this->defaultParams['excludeInstanceOf']
			),
			'maxJobs' => (int)$this->getOption(
				'max-jobs',
				$this->defaultParams['maxJobs']
			),
			'dryRun' => (bool)$this->getOption(
				'dry-run',
				$this->defaultParams['dryRun']
			),
			'verbose' => (bool)$this->getOption(
				'verbose',
				$this->defaultParams['verbose']
			),
			'queue' => (bool)$this->getOption(
				'queue',
				$this->defaultParams['queue']
			),
			'batchSize' => $this->mBatchSize,
		] + $this->defaultParams;
	}

}

$maintClass = SendNotificationsForUnillustratedWatchedTitles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
