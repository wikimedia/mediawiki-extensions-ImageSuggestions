<?php

namespace MediaWiki\Extension\ImageSuggestions\Maintenance;

use MediaWiki\Extension\ImageSuggestions\NotificationsJob;
use MediaWiki\Logger\ConsoleSpi;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Maintenance\Maintenance;
use Psr\Log\LogLevel;

class SendNotificationsForUnillustratedWatchedTitles extends Maintenance {
	private const array DEFAULT_PARAMS = [
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
			"Minimum edit count for users to receive notification, default: " . self::DEFAULT_PARAMS['minEditCount'],
			false,
			true
		);
		$this->addOption(
			'min-confidence',
			// phpcs:ignore
			"Minimum confidence score (0-100) required to send notification for an article-level suggestion, default: " .  self::DEFAULT_PARAMS['minConfidence'],
			false,
			true
		);
		$this->addOption(
			'min-confidence-section',
			// phpcs:ignore
			"Minimum confidence score (0-100) required to send notification for a section-level suggestion, default: " .  self::DEFAULT_PARAMS['minConfidenceSection'],
			false,
			true
		);
		$this->addOption(
			'max-notifications-per-user',
			// phpcs:ignore
			"Maximum amount of notifications to create per user, per run of this script, default: " . self::DEFAULT_PARAMS['maxNotificationsPerUser'],
			false,
			true
		);
		$defaultExcludeInstanceOf =
			self::DEFAULT_PARAMS['excludeInstanceOf'] ?
				implode( ', ', self::DEFAULT_PARAMS['excludeInstanceOf'] ) :
				'<none>';
		$this->addOption(
			'exclude-instance-of',
			// phpcs:ignore
			"Item Q-id of which the page's associated entity must not be an instance of, default: $defaultExcludeInstanceOf",
			false,
			true,
			false,
			true
		);
		$this->addOption(
			'max-jobs',
			// phpcs:ignore
			"Maximum amount of jobs that will be run (used for debugging), default: null",
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

		$job = $this->getServiceContainer()->getJobFactory()
			->newJob( 'ImageSuggestionsNotifications', $params );
		'@phan-var NotificationsJob $job';
		$job->invoke( $params['queue'] );
	}

	public function getJobParams(): array {
		return [
			'minEditCount' => (int)$this->getOption(
				'min-edit-count',
				self::DEFAULT_PARAMS['minEditCount']
			),
			'minConfidence' => (int)$this->getOption(
				'min-confidence',
				self::DEFAULT_PARAMS['minConfidence']
			),
			'minConfidenceSection' => (int)$this->getOption(
				'min-confidence-section',
				self::DEFAULT_PARAMS['minConfidenceSection']
			),
			'maxNotificationsPerUser' => (int)$this->getOption(
				'max-notifications-per-user',
				self::DEFAULT_PARAMS['maxNotificationsPerUser']
			),
			'excludeInstanceOf' => (array)$this->getOption(
				'exclude-instance-of',
				self::DEFAULT_PARAMS['excludeInstanceOf']
			),
			'maxJobs' => (int)$this->getOption(
				'max-jobs',
				self::DEFAULT_PARAMS['maxJobs']
			),
			'dryRun' => (bool)$this->getOption(
				'dry-run',
				self::DEFAULT_PARAMS['dryRun']
			),
			'verbose' => (bool)$this->getOption(
				'verbose',
				self::DEFAULT_PARAMS['verbose']
			),
			'queue' => (bool)$this->getOption(
				'queue',
				self::DEFAULT_PARAMS['queue']
			),
			'batchSize' => $this->mBatchSize,
		] + self::DEFAULT_PARAMS;
	}

}

$maintClass = SendNotificationsForUnillustratedWatchedTitles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
