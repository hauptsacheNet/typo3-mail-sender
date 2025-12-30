<?php

declare(strict_types=1);

namespace Hn\MailSender\Service;

use Hn\MailSender\Import\SenderAddressSourceProviderInterface;
use Hn\MailSender\Import\ValueObject\SenderAddress;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Service to import sender addresses from multiple source providers
 *
 * This service collects sender addresses from all registered providers
 * and ensures they exist in the database with validation.
 */
class SenderAddressImportService
{
    /**
     * @param iterable<SenderAddressSourceProviderInterface> $sourceProviders
     */
    public function __construct(
        private readonly iterable $sourceProviders,
        private readonly ValidationService $validationService,
        private readonly ConnectionPool $connectionPool,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Import sender addresses from all providers
     *
     * Collects addresses from all registered providers, creates missing records,
     * and triggers validation for newly created addresses.
     *
     * @return int[] UIDs of all imported/existing sender addresses
     */
    public function importFromAllProviders(): array
    {
        $addresses = $this->collectAllAddresses();
        $uids = [];

        foreach ($addresses as $address) {
            $uid = $this->ensureAddressExists($address);
            if ($uid !== null) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    /**
     * Collect addresses from all providers, deduplicated by email
     *
     * @return SenderAddress[]
     */
    private function collectAllAddresses(): array
    {
        $addresses = [];
        $seen = [];

        foreach ($this->sourceProviders as $provider) {
            foreach ($provider->getSenderAddresses() as $address) {
                $email = strtolower($address->email);
                if (!isset($seen[$email])) {
                    $addresses[] = $address;
                    $seen[$email] = true;
                }
            }
        }

        return $addresses;
    }

    /**
     * Ensure a sender address exists in the database
     *
     * If the address doesn't exist yet, it will be created and validated immediately.
     *
     * @return int|null The UID of the sender address, or null on failure
     */
    private function ensureAddressExists(SenderAddress $address): ?int
    {
        // Check if address already exists
        $existingUid = $this->findExistingAddress($address->email);
        if ($existingUid !== null) {
            return $existingUid;
        }

        // Create new record
        $uid = $this->createSenderAddress($address);

        // Validate immediately (non-blocking - errors are logged, not thrown)
        try {
            $this->validationService->validateSenderAddress($uid);
        } catch (\Throwable $e) {
            // Validation failure should not prevent import - address stays in pending state
            $this->logger?->warning(
                'Validation failed during import for {email}: {message}',
                ['email' => $address->email, 'message' => $e->getMessage()]
            );
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
    private function createSenderAddress(SenderAddress $address): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_mailsender_address');

        $connection->insert(
            'tx_mailsender_address',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'sender_address' => $address->email,
                'sender_name' => $address->name,
                'validation_status' => 'pending',
            ]
        );

        return (int)$connection->lastInsertId();
    }
}
