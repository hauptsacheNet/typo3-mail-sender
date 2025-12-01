<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Unit\Validation\Validator;

use Hn\MailSender\Validation\Validator\DmarcValidator;
use Hn\MailSender\Validation\ValueObject\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Testable DmarcValidator that allows mocking DNS responses
 */
class TestableDmarcValidator extends DmarcValidator
{
    private ?string $mockedDmarcRecord = null;

    public function setMockedDmarcRecord(?string $record): void
    {
        $this->mockedDmarcRecord = $record;
    }

    protected function fetchDmarcRecord(string $dmarcDomain): ?string
    {
        return $this->mockedDmarcRecord;
    }
}

/**
 * Test case for DmarcValidator drift detection
 */
class DmarcValidatorTest extends TestCase
{
    private const SAMPLE_DMARC_RECORD = 'v=DMARC1; p=reject; rua=mailto:dmarc@example.com';

    public function testNoDriftWhenDmarcRecordUnchanged(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord(self::SAMPLE_DMARC_RECORD);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dmarc' => ['result' => 'pass'],
            ],
            'previous_validation' => [
                'DMARC Validator' => [
                    'dmarc_record' => self::SAMPLE_DMARC_RECORD,
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('passed', $result->getMessage());
        self::assertFalse($result->getDetails()['dns_changed']);
    }

    public function testDriftDetectedWhenDmarcRecordChanged(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord('v=DMARC1; p=none; rua=mailto:new@example.com');

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dmarc' => ['result' => 'pass'],
            ],
            'previous_validation' => [
                'DMARC Validator' => [
                    'dmarc_record' => self::SAMPLE_DMARC_RECORD,
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertStringContainsString('DNS record has changed', $result->getMessage());
        self::assertTrue($result->getDetails()['dns_changed']);
        self::assertContains(
            'DMARC record changed since test email was uploaded. Upload new test email to verify current configuration.',
            $result->getDetails()['warnings']
        );
    }

    public function testNoDriftCheckOnFirstValidation(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord(self::SAMPLE_DMARC_RECORD);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dmarc' => ['result' => 'pass'],
            ],
            // No previous_validation - first time
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertFalse($result->getDetails()['dns_changed']);
    }

    public function testEmlFailResultReturnsInvalid(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord(self::SAMPLE_DMARC_RECORD);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dmarc' => ['result' => 'fail'],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_INVALID, $result->getStatus());
        self::assertStringContainsString('failed', $result->getMessage());
    }

    public function testFallsBackToDnsWhenNoEmlDmarcResult(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord(self::SAMPLE_DMARC_RECORD);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                // No DMARC result in EML
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        // Should fall back to DNS-only result
        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('reject', $result->getMessage());
    }

    public function testDnsOnlyValidationWithoutEml(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord(self::SAMPLE_DMARC_RECORD);

        $result = $validator->validate('test@example.com', 'example.com', null);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('reject', $result->getMessage());
    }

    public function testMissingDmarcRecordReturnsWarning(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord(null);

        $result = $validator->validate('test@example.com', 'example.com', null);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertStringContainsString('No DMARC record found', $result->getMessage());
    }

    public function testDmarcPolicyNoneReturnsWarning(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord('v=DMARC1; p=none');

        $result = $validator->validate('test@example.com', 'example.com', null);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertContains(
            'DMARC policy is set to "none" (monitoring only) - unauthorized emails are not blocked',
            $result->getDetails()['warnings']
        );
    }

    public function testMissingRuaReturnsWarning(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord('v=DMARC1; p=reject');

        $result = $validator->validate('test@example.com', 'example.com', null);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertContains(
            'No aggregate report URI (rua) configured - you won\'t receive authentication reports',
            $result->getDetails()['warnings']
        );
    }

    public function testDetailsIncludeParsedDmarcInfo(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord('v=DMARC1; p=quarantine; aspf=s; adkim=r; rua=mailto:dmarc@example.com; pct=50');

        $result = $validator->validate('test@example.com', 'example.com', null);
        $details = $result->getDetails();

        self::assertArrayHasKey('dmarc_record', $details);
        self::assertArrayHasKey('parsed', $details);

        self::assertSame('quarantine', $details['parsed']['p']);
        self::assertSame('s', $details['parsed']['aspf']);
        self::assertSame('r', $details['parsed']['adkim']);
        self::assertSame('mailto:dmarc@example.com', $details['parsed']['rua']);
        self::assertSame('50', $details['parsed']['pct']);

        self::assertArrayHasKey('spf_alignment', $details);
        self::assertSame('strict', $details['spf_alignment']);
        self::assertArrayHasKey('dkim_alignment', $details);
        self::assertSame('relaxed', $details['dkim_alignment']);
    }

    public function testInvalidPolicyReturnsInvalid(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord('v=DMARC1; p=invalid');

        $result = $validator->validate('test@example.com', 'example.com', null);

        self::assertSame(ValidationResult::STATUS_INVALID, $result->getStatus());
        self::assertStringContainsString('Unknown policy value', $result->getMessage());
    }

    public function testMissingPolicyTagReturnsInvalid(): void
    {
        $validator = new TestableDmarcValidator();
        $validator->setMockedDmarcRecord('v=DMARC1; rua=mailto:dmarc@example.com');

        $result = $validator->validate('test@example.com', 'example.com', null);

        self::assertSame(ValidationResult::STATUS_INVALID, $result->getStatus());
        self::assertStringContainsString('Missing required policy', $result->getMessage());
    }
}
