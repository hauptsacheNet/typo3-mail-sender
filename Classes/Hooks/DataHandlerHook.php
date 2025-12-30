<?php

declare(strict_types=1);

namespace Hn\MailSender\Hooks;

use Hn\MailSender\Service\ValidationService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler Hook
 *
 * Automatically validates sender addresses when they are created or updated.
 * Triggers validation if the sender_address field has changed.
 */
class DataHandlerHook implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TABLE_NAME = 'tx_mailsender_address';

    /**
     * Hook: processDatamap_afterDatabaseOperations
     *
     * Called after a record has been inserted or updated.
     *
     * @param string $status 'new' or 'update'
     * @param string $table The table name
     * @param int|string $id The record UID (or 'NEW...' for new records)
     * @param array<string, mixed> $fieldArray The fields that were updated
     * @param DataHandler $dataHandler The DataHandler instance
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        // Only process our table
        if ($table !== self::TABLE_NAME) {
            return;
        }

        // Get the actual UID for new records
        if ($status === 'new') {
            $id = $dataHandler->substNEWwithIDs[$id] ?? $id;
        }

        // Ensure we have a valid UID
        if (!is_numeric($id)) {
            return;
        }

        $uid = (int)$id;

        // Check if sender_address field was changed
        $shouldValidate = false;

        if ($status === 'new') {
            // Always validate new records
            $shouldValidate = true;
        } elseif (isset($fieldArray['sender_address'])) {
            // Validate if sender_address was updated
            $shouldValidate = true;
        }

        if (!$shouldValidate) {
            return;
        }

        // Run validation
        try {
            $validationService = GeneralUtility::makeInstance(ValidationService::class);
            $validationService->validateSenderAddress($uid);
        } catch (\Throwable $e) {
            // Log error but don't break the save operation
            $this->logger?->warning(
                'Mail Sender validation failed for UID {uid}: {message}',
                ['uid' => $uid, 'message' => $e->getMessage(), 'exception' => $e]
            );
        }
    }
}
