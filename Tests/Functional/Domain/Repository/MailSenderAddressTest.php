<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Functional\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use Hn\MailSender\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Test case for Mail Sender Address records
 */
class MailSenderAddressTest extends AbstractFunctionalTest
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->getConnectionForTable('tx_mailsender_address');
    }

    public function testCanCreateMailSenderAddress(): void
    {
        $data = [
            'pid' => 0,
            'sender_address' => 'test@example.com',
            'sender_name' => 'Test Sender',
            'validation_status' => 'pending',
            'hidden' => 0,
        ];

        $this->connection->insert('tx_mailsender_address', $data);
        $uid = (int)$this->connection->lastInsertId();

        self::assertGreaterThan(0, $uid);

        $record = $this->connection->select(
            ['*'],
            'tx_mailsender_address',
            ['uid' => $uid]
        )->fetchAssociative();

        self::assertIsArray($record);
        self::assertSame('test@example.com', $record['sender_address']);
        self::assertSame('Test Sender', $record['sender_name']);
        self::assertSame('pending', $record['validation_status']);
        self::assertSame(0, (int)$record['hidden']);
    }

    public function testCanUpdateValidationStatus(): void
    {
        $data = [
            'pid' => 0,
            'sender_address' => 'valid@example.com',
            'sender_name' => 'Valid Sender',
            'validation_status' => 'pending',
        ];

        $this->connection->insert('tx_mailsender_address', $data);
        $uid = (int)$this->connection->lastInsertId();

        // Update validation status
        $this->connection->update(
            'tx_mailsender_address',
            [
                'validation_status' => 'valid',
                'validation_last_check' => time(),
                'validation_result' => json_encode(['status' => 'success']),
            ],
            ['uid' => $uid]
        );

        $record = $this->connection->select(
            ['*'],
            'tx_mailsender_address',
            ['uid' => $uid]
        )->fetchAssociative();

        self::assertSame('valid', $record['validation_status']);
        self::assertGreaterThan(0, (int)$record['validation_last_check']);
        self::assertStringContainsString('success', $record['validation_result']);
    }

    public function testCanSoftDeleteMailSenderAddress(): void
    {
        $data = [
            'pid' => 0,
            'sender_address' => 'delete@example.com',
            'sender_name' => 'Delete Test',
            'validation_status' => 'pending',
        ];

        $this->connection->insert('tx_mailsender_address', $data);
        $uid = (int)$this->connection->lastInsertId();

        // Soft delete
        $this->connection->update(
            'tx_mailsender_address',
            ['deleted' => 1],
            ['uid' => $uid]
        );

        // Query directly to bypass restrictions
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mailsender_address');
        $queryBuilder->getRestrictions()->removeAll();
        $record = $queryBuilder
            ->select('*')
            ->from('tx_mailsender_address')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($record);
        self::assertSame(1, (int)$record['deleted']);
    }

    public function testCanHideMailSenderAddress(): void
    {
        $data = [
            'pid' => 0,
            'sender_address' => 'hide@example.com',
            'sender_name' => 'Hide Test',
            'validation_status' => 'pending',
            'hidden' => 0,
        ];

        $this->connection->insert('tx_mailsender_address', $data);
        $uid = (int)$this->connection->lastInsertId();

        // Hide record
        $this->connection->update(
            'tx_mailsender_address',
            ['hidden' => 1],
            ['uid' => $uid]
        );

        // Query directly to bypass restrictions
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mailsender_address');
        $queryBuilder->getRestrictions()->removeAll();
        $record = $queryBuilder
            ->select('*')
            ->from('tx_mailsender_address')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($record);
        self::assertSame(1, (int)$record['hidden']);
    }
}
