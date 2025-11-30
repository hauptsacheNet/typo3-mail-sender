<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Functional\Service;

use Hn\MailSender\Service\ValidationService;
use Hn\MailSender\Tests\Functional\AbstractFunctionalTest;
use Hn\MailSender\Validation\ValueObject\ValidationResult;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test case for ValidationService
 */
class ValidationServiceTest extends AbstractFunctionalTest
{
    private Connection $connection;
    private ValidationService $validationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->getConnectionForTable('tx_mailsender_address');
        $this->validationService = GeneralUtility::makeInstance(ValidationService::class);
    }

    public function testCanValidateRealEmailAddress(): void
    {
        // Insert test record
        $this->connection->insert('tx_mailsender_address', [
            'pid' => 0,
            'sender_address' => 'marco@hauptsache.net',
            'sender_name' => 'Marco Pfeiffer',
            'validation_status' => 'pending',
        ]);

        $uid = (int)$this->connection->lastInsertId();

        // Run validation
        $results = $this->validationService->validateSenderAddress($uid);

        // Assert results
        self::assertIsArray($results);
        self::assertArrayHasKey('status', $results);
        self::assertArrayHasKey('email', $results);
        self::assertArrayHasKey('domain', $results);
        self::assertArrayHasKey('validators', $results);

        self::assertSame('marco@hauptsache.net', $results['email']);
        self::assertSame('hauptsache.net', $results['domain']);

        // Check that all validators ran
        self::assertArrayHasKey('Email Syntax Validator', $results['validators']);
        self::assertArrayHasKey('MX Validator', $results['validators']);
        self::assertArrayHasKey('DMARC Validator', $results['validators']);
        self::assertArrayHasKey('Email Existence Validator', $results['validators']);

        // Email syntax should always pass for valid syntax
        self::assertSame(
            ValidationResult::STATUS_VALID,
            $results['validators']['Email Syntax Validator']['status']
        );

        // MX should find MX records for hauptsache.net
        self::assertContains(
            $results['validators']['MX Validator']['status'],
            [ValidationResult::STATUS_VALID, ValidationResult::STATUS_WARNING]
        );

        // Verify database was updated
        $record = $this->connection->select(
            ['validation_status', 'validation_last_check', 'validation_result'],
            'tx_mailsender_address',
            ['uid' => $uid]
        )->fetchAssociative();

        self::assertIsArray($record);
        self::assertNotEquals('pending', $record['validation_status']);
        self::assertGreaterThan(0, (int)$record['validation_last_check']);
        self::assertNotEmpty($record['validation_result']);

        // Verify result is valid JSON
        $decodedResult = json_decode($record['validation_result'], true);
        self::assertIsArray($decodedResult);
        self::assertArrayHasKey('validators', $decodedResult);
    }

    public function testCanValidateEmailWithoutDatabase(): void
    {
        $results = $this->validationService->validateEmail('marco@hauptsache.net');

        self::assertIsArray($results);
        self::assertSame('marco@hauptsache.net', $results['email']);
        self::assertSame('hauptsache.net', $results['domain']);

        // Email syntax should pass
        self::assertSame(
            ValidationResult::STATUS_VALID,
            $results['validators']['Email Syntax Validator']['status']
        );
    }

    public function testInvalidEmailSyntax(): void
    {
        $results = $this->validationService->validateEmail('invalid@@example.com');

        self::assertSame(ValidationResult::STATUS_INVALID, $results['status']);
        self::assertSame(
            ValidationResult::STATUS_INVALID,
            $results['validators']['Email Syntax Validator']['status']
        );
    }

    public function testNonExistentDomain(): void
    {
        $results = $this->validationService->validateEmail('test@example-does-not-exist-12345.com');

        // Overall status should be invalid due to missing MX records
        self::assertSame(ValidationResult::STATUS_INVALID, $results['status']);

        // MX validator should fail
        self::assertSame(
            ValidationResult::STATUS_INVALID,
            $results['validators']['MX Validator']['status']
        );
    }

    public function testValidatorsRunInPriorityOrder(): void
    {
        $validators = $this->validationService->getValidators();

        self::assertNotEmpty($validators);

        // Check that validators are ordered by priority
        $previousPriority = -1;
        foreach ($validators as $validator) {
            $priority = $validator->getPriority();
            self::assertGreaterThanOrEqual($previousPriority, $priority);
            $previousPriority = $priority;
        }

        // Verify order: Syntax (5) < MX (10) < DMARC (11) < SPF (12) < Existence (20)
        self::assertSame(5, $validators[0]->getPriority()); // Email Syntax Validator
        self::assertSame(10, $validators[1]->getPriority()); // MX Validator
        self::assertSame(11, $validators[2]->getPriority()); // DMARC Validator
        self::assertSame(12, $validators[3]->getPriority()); // SPF Validator
        self::assertSame(20, $validators[4]->getPriority()); // Email Existence Validator
    }
}
