<?php

declare(strict_types=1);

namespace Hn\MailSender\Service;

use Hn\MailSender\Configuration\MailConfigurationProvider;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Service to import the default sender address from global TYPO3 configuration
 */
class DefaultSenderImportService
{
    public function __construct(
        private readonly MailConfigurationProvider $mailConfigurationProvider,
        private readonly ValidationService $validationService,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Ensure the default sender address from TYPO3 mail configuration exists
     *
     * If the address doesn't exist yet, it will be created and validated immediately.
     *
     * @return int|null The UID of the default sender address, or null if no default is configured
     */
    public function ensureDefaultSenderExists(): ?int
    {
        $defaultAddress = $this->mailConfigurationProvider->getDefaultMailFromAddress();
        if ($defaultAddress === null) {
            return null;
        }

        // Check if address already exists
        $existingUid = $this->findExistingAddress($defaultAddress);
        if ($existingUid !== null) {
            return $existingUid;
        }

        // Create new record
        $defaultName = $this->mailConfigurationProvider->getDefaultMailFromName() ?? '';
        $uid = $this->createSenderAddress($defaultAddress, $defaultName);

        // Validate immediately (non-blocking - errors are logged, not thrown)
        try {
            $this->validationService->validateSenderAddress($uid);
        } catch (\Throwable $e) {
            // Validation failure should not prevent import - address stays in pending state
        }

        return $uid;
    }

    /**
     * Find existing sender address by email
     */
    private function findExistingAddress(string $email): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mailsender_address');
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->select('uid')
            ->from('tx_mailsender_address')
            ->where(
                $queryBuilder->expr()->eq('sender_address', $queryBuilder->createNamedParameter($email)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchOne();

        return $result !== false ? (int)$result : null;
    }

    /**
     * Create a new sender address record
     */
    private function createSenderAddress(string $email, string $name): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_mailsender_address');

        $connection->insert(
            'tx_mailsender_address',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'sender_address' => $email,
                'sender_name' => $name,
                'validation_status' => 'pending',
            ]
        );

        return (int)$connection->lastInsertId();
    }
}
