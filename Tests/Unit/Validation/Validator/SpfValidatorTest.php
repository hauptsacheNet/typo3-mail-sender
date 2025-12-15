<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Unit\Validation\Validator;

use Hn\MailSender\Configuration\MailConfigurationProvider;
use Hn\MailSender\Validation\Validator\SpfValidator;
use Hn\MailSender\Validation\ValueObject\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Testable SpfValidator that allows mocking DNS responses
 */
class TestableSpfValidator extends SpfValidator
{
    private ?string $mockedSpfRecord = null;

    public function setMockedSpfRecord(?string $record): void
    {
        $this->mockedSpfRecord = $record;
    }

    protected function fetchSpfRecord(string $domain): ?string
    {
        return $this->mockedSpfRecord;
    }
}

/**
 * Test case for SpfValidator drift detection
 */
class SpfValidatorTest extends TestCase
{
    private MailConfigurationProvider $configProvider;

    protected function setUp(): void
    {
        $this->configProvider = $this->createMock(MailConfigurationProvider::class);
        // Default: SMTP not configured, so library validation is skipped
        $this->configProvider->method('isSmtpConfigured')->willReturn(false);
    }

    public function testNoDriftWhenDnsRecordUnchanged(): void
    {
        $validator = new TestableSpfValidator($this->configProvider);
        $validator->setMockedSpfRecord('v=spf1 include:_spf.example.com ~all');

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'spf' => ['result' => 'pass'],
            ],
            'previous_validation' => [
                'SPF Validator' => [
                    'spf_record' => 'v=spf1 include:_spf.example.com ~all',
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('passed', $result->getMessage());
        self::assertFalse($result->getDetails()['dns_changed']);
    }

    public function testDriftDetectedWhenDnsRecordChanged(): void
    {
        $validator = new TestableSpfValidator($this->configProvider);
        $validator->setMockedSpfRecord('v=spf1 include:_spf.newprovider.com ~all');

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'spf' => ['result' => 'pass'],
            ],
            'previous_validation' => [
                'SPF Validator' => [
                    'spf_record' => 'v=spf1 include:_spf.oldprovider.com ~all',
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertStringContainsString('DNS record has changed', $result->getMessage());
        self::assertTrue($result->getDetails()['dns_changed']);
        self::assertContains(
            'SPF record changed since test email was uploaded. Upload new test email to verify current configuration.',
            $result->getDetails()['warnings']
        );
    }

    public function testNoDriftCheckOnFirstValidation(): void
    {
        $validator = new TestableSpfValidator($this->configProvider);
        $validator->setMockedSpfRecord('v=spf1 include:_spf.example.com ~all');

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'spf' => ['result' => 'pass'],
            ],
            // No previous_validation - first time
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertFalse($result->getDetails()['dns_changed']);
    }

    public function testEmlFailResultReturnsInvalid(): void
    {
        $validator = new TestableSpfValidator($this->configProvider);
        $validator->setMockedSpfRecord('v=spf1 -all');

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'spf' => ['result' => 'fail'],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_INVALID, $result->getStatus());
        self::assertStringContainsString('failed', $result->getMessage());
    }

    public function testEmlSoftfailResultReturnsWarning(): void
    {
        $validator = new TestableSpfValidator($this->configProvider);
        $validator->setMockedSpfRecord('v=spf1 ~all');

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'spf' => ['result' => 'softfail'],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertStringContainsString('soft fail', $result->getMessage());
    }

    public function testFallsBackToDnsWhenNoEmlSpfResult(): void
    {
        $validator = new TestableSpfValidator($this->configProvider);
        $validator->setMockedSpfRecord('v=spf1 include:_spf.example.com ~all');

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                // No SPF result in EML
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        // Should fall back to DNS-only result (skipped because SMTP not configured)
        self::assertSame(ValidationResult::STATUS_SKIPPED, $result->getStatus());
    }

    public function testDetailsIncludeBothEmlAndDnsResults(): void
    {
        $validator = new TestableSpfValidator($this->configProvider);
        $validator->setMockedSpfRecord('v=spf1 include:_spf.example.com ~all');

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'spf' => ['result' => 'pass', 'details' => ['smtp.mailfrom' => 'sender@example.com']],
            ],
            'previous_validation' => [
                'SPF Validator' => [
                    'spf_record' => 'v=spf1 include:_spf.example.com ~all',
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);
        $details = $result->getDetails();

        self::assertArrayHasKey('spf_record', $details);
        self::assertArrayHasKey('eml_result', $details);
        self::assertArrayHasKey('dns_result', $details);
        self::assertArrayHasKey('dns_changed', $details);
        self::assertArrayHasKey('eml_file_hash', $details);

        self::assertSame('v=spf1 include:_spf.example.com ~all', $details['spf_record']);
        self::assertSame('pass', $details['eml_result']);
        self::assertSame('abc123', $details['eml_file_hash']);
    }
}
