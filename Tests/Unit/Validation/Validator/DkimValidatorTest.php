<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Unit\Validation\Validator;

use Hn\MailSender\Validation\Validator\DkimValidator;
use Hn\MailSender\Validation\ValueObject\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Testable DkimValidator that allows mocking DNS responses
 */
class TestableDkimValidator extends DkimValidator
{
    private ?string $mockedDkimKey = null;

    public function setMockedDkimKey(?string $key): void
    {
        $this->mockedDkimKey = $key;
    }

    protected function fetchDkimKey(string $dkimDnsRecord): ?string
    {
        return $this->mockedDkimKey;
    }
}

/**
 * Test case for DkimValidator drift detection
 */
class DkimValidatorTest extends TestCase
{
    private const SAMPLE_DKIM_KEY = 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4...';

    public function testNoDriftWhenDkimKeyUnchanged(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(self::SAMPLE_DKIM_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'pass',
                    'selector' => 'mail',
                    'domain' => 'example.com',
                ],
            ],
            'previous_validation' => [
                'DKIM Validator' => [
                    'dkim_public_key' => self::SAMPLE_DKIM_KEY,
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('passed', $result->getMessage());
        self::assertFalse($result->getDetails()['dns_changed']);
    }

    public function testDriftDetectedWhenDkimKeyChanged(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey('v=DKIM1; k=rsa; p=NEWKEY123...');

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'pass',
                    'selector' => 'mail',
                    'domain' => 'example.com',
                ],
            ],
            'previous_validation' => [
                'DKIM Validator' => [
                    'dkim_public_key' => self::SAMPLE_DKIM_KEY,
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertStringContainsString('DNS key has changed', $result->getMessage());
        self::assertTrue($result->getDetails()['dns_changed']);
    }

    public function testDriftDetectedWhenDkimKeyRemoved(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(null); // Key no longer exists

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'pass',
                    'selector' => 'mail',
                    'domain' => 'example.com',
                ],
            ],
            'previous_validation' => [
                'DKIM Validator' => [
                    'dkim_public_key' => self::SAMPLE_DKIM_KEY,
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertStringContainsString('no longer exists', $result->getMessage());
    }

    public function testNoDriftCheckOnFirstValidation(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(self::SAMPLE_DKIM_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'pass',
                    'selector' => 'mail',
                    'domain' => 'example.com',
                ],
            ],
            // No previous_validation - first time
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertFalse($result->getDetails()['dns_changed']);
    }

    public function testEmlFailResultReturnsInvalid(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(self::SAMPLE_DKIM_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'fail',
                    'selector' => 'mail',
                    'domain' => 'example.com',
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_INVALID, $result->getStatus());
        self::assertStringContainsString('failed', $result->getMessage());
    }

    public function testDomainAlignmentPassesWhenDkimPasses(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(self::SAMPLE_DKIM_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'pass',
                    'selector' => 'mail',
                    'domain' => 'otherdomain.com', // Different from sender domain
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        // Domain alignment is DMARC's concern, not DKIM's - if DKIM passed, that's valid
        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('passed', $result->getMessage());
        self::assertFalse($result->getDetails()['domain_aligned']);
        self::assertStringContainsString('domain alignment is checked by DMARC', $result->getDetails()['info']);
    }

    public function testDetailsIncludeDkimInfo(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(self::SAMPLE_DKIM_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'pass',
                    'selector' => 'mail',
                    'domain' => 'example.com',
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);
        $details = $result->getDetails();

        self::assertArrayHasKey('dkim_selector', $details);
        self::assertArrayHasKey('dkim_domain', $details);
        self::assertArrayHasKey('dkim_dns_record', $details);
        self::assertArrayHasKey('dkim_public_key', $details);
        self::assertArrayHasKey('domain_aligned', $details);
        self::assertArrayHasKey('eml_file_hash', $details);

        self::assertSame('mail', $details['dkim_selector']);
        self::assertSame('example.com', $details['dkim_domain']);
        self::assertSame('mail._domainkey.example.com', $details['dkim_dns_record']);
        self::assertTrue($details['domain_aligned']);
    }

    public function testRequiresEmlFileForValidation(): void
    {
        $validator = new TestableDkimValidator();

        $result = $validator->validate('test@example.com', 'example.com', null);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertStringContainsString('requires uploaded test email', $result->getMessage());
    }

    public function testFallbackToDkimSignatureWhenNoAuthResults(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(self::SAMPLE_DKIM_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [],
            'dkim_signature' => [
                'domain' => 'example.com',
                'selector' => 'mail',
                'algorithm' => 'rsa-sha256',
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        // Should use DKIM signature fallback
        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('public key exists', $result->getMessage());
    }
}
