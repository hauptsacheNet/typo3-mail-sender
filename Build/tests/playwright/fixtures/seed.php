<?php

declare(strict_types=1);

/**
 * Seeds deterministic test data for the Playwright E2E suite.
 *
 * Run from the repository root after `typo3 setup`:
 *   php Build/tests/playwright/fixtures/seed.php [<repo-root>]
 *
 * Inserts a pre-validated sender address so that:
 *   - the validation backend module has a record to display and revalidate,
 *   - the ext:form sender-address dropdown has a validated option to offer.
 *
 * Bootstraps TYPO3 in CLI mode so it works regardless of the configured
 * database driver (SQLite locally/CI, MySQL in the Docker path).
 */

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$root = $argv[1] ?? dirname(__DIR__, 4);
$classLoader = require $root . '/vendor/autoload.php';

SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
Bootstrap::init($classLoader);

$now = time();
$seedAddress = 'valid-seed@example.com';

$connection = GeneralUtility::makeInstance(ConnectionPool::class)
    ->getConnectionForTable('tx_mailsender_address');

// Idempotent: remove any previous seed before re-inserting.
$connection->delete('tx_mailsender_address', ['sender_address' => $seedAddress]);

$connection->insert('tx_mailsender_address', [
    'pid' => 0,
    'tstamp' => $now,
    'crdate' => $now,
    'deleted' => 0,
    'hidden' => 0,
    'sender_address' => $seedAddress,
    'sender_name' => 'E2E Seed Sender',
    'validation_status' => 'valid',
    'validation_last_check' => $now,
    'validation_result' => json_encode([
        'status' => 'valid',
        'email' => $seedAddress,
        'domain' => 'example.com',
        'timestamp' => $now,
        'validators' => [],
        'errors' => [],
    ], JSON_PRETTY_PRINT),
    'eml_file' => 0,
]);

fwrite(STDOUT, "Seeded sender address: {$seedAddress}\n");
