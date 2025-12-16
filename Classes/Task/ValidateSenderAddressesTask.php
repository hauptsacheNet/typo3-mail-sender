<?php

declare(strict_types=1);

namespace Hn\MailSender\Task;

use Hn\MailSender\Service\DefaultSenderImportService;
use Hn\MailSender\Service\ValidationService;
use Hn\MailSender\Service\WebhookNotificationService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler task to validate all sender addresses
 *
 * This task runs through all configured sender addresses and validates them,
 * updating their validation status in the database.
 */
class ValidateSenderAddressesTask extends AbstractTask
{
    /**
     * Execute the task
     *
     * @return bool Returns true on successful execution
     */
    public function execute(): bool
    {
        // Ensure default sender from TYPO3 configuration exists
        $defaultSenderImportService = GeneralUtility::makeInstance(DefaultSenderImportService::class);
        $defaultSenderImportService->ensureDefaultSenderExists();

        $validationService = GeneralUtility::makeInstance(ValidationService::class);
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_mailsender_address');
        $queryBuilder->getRestrictions()->removeAll();

        $senderAddresses = $queryBuilder
            ->select('uid', 'sender_address')
            ->from('tx_mailsender_address')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $successCount = 0;
        $errorCount = 0;

        foreach ($senderAddresses as $sender) {
            try {
                $validationService->validateSenderAddress($sender['uid']);
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                // Log the error but continue with other addresses
                $this->logger?->error(
                    'Failed to validate sender address {email} (UID: {uid}): {message}',
                    [
                        'email' => $sender['sender_address'],
                        'uid' => $sender['uid'],
                        'message' => $e->getMessage(),
                    ]
                );
            }
        }

        // Log summary
        $this->logger?->info(
            'Sender address validation completed: {success} successful, {errors} failed',
            [
                'success' => $successCount,
                'errors' => $errorCount,
            ]
        );

        // Send webhook notification if there are issues
        $this->sendWebhookNotification($connectionPool);

        // Return true unless all validations failed
        return $errorCount === 0 || $successCount > 0;
    }

    /**
     * Send webhook notification about validation results
     */
    private function sendWebhookNotification(ConnectionPool $connectionPool): void
    {
        try {
            $webhookService = GeneralUtility::makeInstance(WebhookNotificationService::class);

            if (!$webhookService->isConfigured()) {
                return;
            }

            // Get validation statistics
            $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_mailsender_address');
            $queryBuilder->getRestrictions()->removeAll();

            $results = $queryBuilder
                ->select('validation_status')
                ->addSelectLiteral('COUNT(*) AS count')
                ->from('tx_mailsender_address')
                ->where(
                    $queryBuilder->expr()->eq('deleted', 0)
                )
                ->groupBy('validation_status')
                ->executeQuery()
                ->fetchAllAssociative();

            $stats = [
                'total' => 0,
                'valid' => 0,
                'warning' => 0,
                'invalid' => 0,
                'pending' => 0,
            ];

            foreach ($results as $row) {
                $count = (int)$row['count'];
                $stats['total'] += $count;
                $status = $row['validation_status'] ?? 'pending';
                if (isset($stats[$status])) {
                    $stats[$status] = $count;
                } else {
                    $stats['pending'] += $count;
                }
            }

            // Get failed addresses for notification
            $failedAddresses = [];
            if ($stats['invalid'] > 0 || $stats['warning'] > 0) {
                $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_mailsender_address');
                $queryBuilder->getRestrictions()->removeAll();

                $failed = $queryBuilder
                    ->select('sender_address', 'validation_status', 'validation_result')
                    ->from('tx_mailsender_address')
                    ->where(
                        $queryBuilder->expr()->eq('deleted', 0),
                        $queryBuilder->expr()->in('validation_status', $queryBuilder->createNamedParameter(
                            ['invalid', 'warning'],
                            \Doctrine\DBAL\ArrayParameterType::STRING
                        ))
                    )
                    ->executeQuery()
                    ->fetchAllAssociative();

                foreach ($failed as $row) {
                    $errors = [];
                    if ($row['validation_result']) {
                        $resultData = json_decode($row['validation_result'], true);
                        $errors = $resultData['errors'] ?? [];
                    }
                    $failedAddresses[] = [
                        'email' => $row['sender_address'],
                        'status' => $row['validation_status'],
                        'errors' => $errors,
                    ];
                }
            }

            // Send notification
            $webhookService->notifyValidationResults($stats, $failedAddresses);

        } catch (\Exception $e) {
            $this->logger?->warning(
                'Failed to send webhook notification: {message}',
                ['message' => $e->getMessage()]
            );
        }
    }

    /**
     * Get additional information about the task
     *
     * @return string Information to display in scheduler module
     */
    public function getAdditionalInformation(): string
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_mailsender_address');
        $queryBuilder->getRestrictions()->removeAll();

        $count = $queryBuilder
            ->count('uid')
            ->from('tx_mailsender_address')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchOne();

        return sprintf('Validates %d sender address(es)', $count);
    }
}
