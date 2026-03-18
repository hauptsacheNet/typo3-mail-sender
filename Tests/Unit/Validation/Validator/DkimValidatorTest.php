<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Unit\Validation\Validator;

use Hn\MailSender\Validation\Validator\DkimValidator;
use Hn\MailSender\Validation\ValueObject\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Testable DkimValidator that allows mocking DNS responses per record
 */
class TestableDkimValidator extends DkimValidator
{
    private array $mockedDkimKeys = [];
    private ?string $defaultMockedKey = null;

    public function setMockedDkimKey(?string $key): void
    {
        $this->defaultMockedKey = $key;
    }

    public function setMockedDkimKeyForRecord(string $record, ?string $key): void
    {
        $this->mockedDkimKeys[$record] = $key;
    }

    protected function fetchDkimKey(string $dkimDnsRecord): ?string
    {
        if (array_key_exists($dkimDnsRecord, $this->mockedDkimKeys)) {
            return $this->mockedDkimKeys[$dkimDnsRecord];
        }
        return $this->defaultMockedKey;
    }
}

/**
 * Test case for DkimValidator
 */
class DkimValidatorTest extends TestCase
{
    private const SAMPLE_DKIM_KEY = 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4...';
    private const SAMPLE_ED25519_KEY = 'v=DKIM1; k=ed25519; p=WXYapoFcTdW0GjTLrjTUZgMYDYfcUwMrKs++rbueq8Q=';

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
                'dkim_results' => [
                    ['result' => 'pass', 'selector' => 'mail', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [],
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
                'dkim_results' => [
                    ['result' => 'pass', 'selector' => 'mail', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [],
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
                'dkim_results' => [
                    ['result' => 'pass', 'selector' => 'mail', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [],
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
                'dkim_results' => [
                    ['result' => 'pass', 'selector' => 'mail', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [],
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
                'dkim_results' => [
                    ['result' => 'fail', 'selector' => 'mail', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [],
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
                'dkim_results' => [
                    ['result' => 'pass', 'selector' => 'mail', 'domain' => 'otherdomain.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [],
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
                'dkim_results' => [
                    ['result' => 'pass', 'selector' => 'mail', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [],
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

        self::assertSame(ValidationResult::STATUS_SKIPPED, $result->getStatus());
        self::assertStringContainsString('requires uploaded test email', $result->getMessage());
    }

    public function testFallbackToDkimSignatureWhenNoAuthResults(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(self::SAMPLE_DKIM_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [],
            'dkim_signatures' => [
                [
                    'domain' => 'example.com',
                    'selector' => 'mail',
                    'algorithm' => 'rsa-sha256',
                ],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        // Should use DKIM signature fallback
        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('public key', $result->getMessage());
    }

    // --- New multi-signature tests ---

    /**
     * The exact scenario from the bug report:
     * One signature passes, one returns neutral, but DNS keys exist for both.
     */
    public function testPassWithNeutralSecondSignatureDnsKeyExists(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKeyForRecord('20250116rsa._domainkey.leibniz-ipn.de', self::SAMPLE_DKIM_KEY);
        $validator->setMockedDkimKeyForRecord('20250116ed25519._domainkey.leibniz-ipn.de', self::SAMPLE_ED25519_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'pass',
                    'selector' => '20250116rsa',
                    'domain' => 'leibniz-ipn.de',
                    'details' => [],
                ],
                'dkim_results' => [
                    ['result' => 'pass', 'selector' => '20250116rsa', 'domain' => 'leibniz-ipn.de', 'details' => []],
                    ['result' => 'neutral', 'selector' => null, 'domain' => 'leibniz-ipn.de', 'details' => ['comment' => 'no key']],
                ],
            ],
            'dkim_signatures' => [
                ['domain' => 'leibniz-ipn.de', 'selector' => '20250116rsa', 'algorithm' => 'rsa-sha256'],
                ['domain' => 'leibniz-ipn.de', 'selector' => '20250116ed25519', 'algorithm' => 'ed25519-sha256'],
            ],
        ];

        $result = $validator->validate('fabius@leibniz-ipn.de', 'leibniz-ipn.de', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('passed', $result->getMessage());
    }

    /**
     * One pass, one neutral, but DNS key genuinely missing for the neutral one.
     * Should still be valid because one passed.
     */
    public function testPassWithNeutralSecondSignatureDnsKeyMissing(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKeyForRecord('selector1._domainkey.example.com', self::SAMPLE_DKIM_KEY);
        $validator->setMockedDkimKeyForRecord('selector2._domainkey.example.com', null); // Missing key

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'pass',
                    'selector' => 'selector1',
                    'domain' => 'example.com',
                    'details' => [],
                ],
                'dkim_results' => [
                    ['result' => 'pass', 'selector' => 'selector1', 'domain' => 'example.com', 'details' => []],
                    ['result' => 'neutral', 'selector' => 'selector2', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [
                ['domain' => 'example.com', 'selector' => 'selector1', 'algorithm' => 'rsa-sha256'],
                ['domain' => 'example.com', 'selector' => 'selector2', 'algorithm' => 'ed25519-sha256'],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        // Pass wins — still valid even though second key is missing
        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
    }

    /**
     * All results neutral but DNS keys exist for all signatures.
     * Mail server didn't verify, but keys are there.
     */
    public function testAllNeutralButDnsKeysExist(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKeyForRecord('selector1._domainkey.example.com', self::SAMPLE_DKIM_KEY);
        $validator->setMockedDkimKeyForRecord('selector2._domainkey.example.com', self::SAMPLE_ED25519_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'neutral',
                    'selector' => 'selector1',
                    'domain' => 'example.com',
                    'details' => [],
                ],
                'dkim_results' => [
                    ['result' => 'neutral', 'selector' => 'selector1', 'domain' => 'example.com', 'details' => []],
                    ['result' => 'neutral', 'selector' => 'selector2', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [
                ['domain' => 'example.com', 'selector' => 'selector1', 'algorithm' => 'rsa-sha256'],
                ['domain' => 'example.com', 'selector' => 'selector2', 'algorithm' => 'ed25519-sha256'],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('DNS keys verified', $result->getMessage());
    }

    /**
     * All results neutral and DNS key genuinely missing.
     * Should warn.
     */
    public function testAllNeutralAndDnsKeyMissing(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(null); // All keys missing

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'neutral',
                    'selector' => 'selector1',
                    'domain' => 'example.com',
                    'details' => [],
                ],
                'dkim_results' => [
                    ['result' => 'neutral', 'selector' => 'selector1', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [
                ['domain' => 'example.com', 'selector' => 'selector1', 'algorithm' => 'rsa-sha256'],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_WARNING, $result->getStatus());
        self::assertStringContainsString('missing', strtolower($result->getMessage()));
    }

    /**
     * One pass, one fail — pass should win.
     */
    public function testPassWithFailStillValid(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(self::SAMPLE_DKIM_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'pass',
                    'selector' => 'selector1',
                    'domain' => 'example.com',
                    'details' => [],
                ],
                'dkim_results' => [
                    ['result' => 'pass', 'selector' => 'selector1', 'domain' => 'example.com', 'details' => []],
                    ['result' => 'fail', 'selector' => 'selector2', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
    }

    /**
     * Only fail, no pass — should be invalid.
     */
    public function testFailWithNoPassIsInvalid(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKey(self::SAMPLE_DKIM_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [
                'dkim' => [
                    'result' => 'fail',
                    'selector' => 'selector1',
                    'domain' => 'example.com',
                    'details' => [],
                ],
                'dkim_results' => [
                    ['result' => 'fail', 'selector' => 'selector1', 'domain' => 'example.com', 'details' => []],
                ],
            ],
            'dkim_signatures' => [],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_INVALID, $result->getStatus());
    }

    /**
     * Fallback with multiple DKIM-Signature headers, all DNS keys present.
     */
    public function testFallbackMultipleSignaturesAllKeysPresent(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKeyForRecord('selector1._domainkey.example.com', self::SAMPLE_DKIM_KEY);
        $validator->setMockedDkimKeyForRecord('selector2._domainkey.example.com', self::SAMPLE_ED25519_KEY);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [],
            'dkim_signatures' => [
                ['domain' => 'example.com', 'selector' => 'selector1', 'algorithm' => 'rsa-sha256'],
                ['domain' => 'example.com', 'selector' => 'selector2', 'algorithm' => 'ed25519-sha256'],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
        self::assertStringContainsString('public key', $result->getMessage());
    }

    /**
     * Fallback with multiple DKIM-Signature headers, one key missing.
     */
    public function testFallbackMultipleSignaturesOneKeyMissing(): void
    {
        $validator = new TestableDkimValidator();
        $validator->setMockedDkimKeyForRecord('selector1._domainkey.example.com', self::SAMPLE_DKIM_KEY);
        $validator->setMockedDkimKeyForRecord('selector2._domainkey.example.com', null);

        $emlData = [
            'file_hash' => 'abc123',
            'authentication_results' => [],
            'dkim_signatures' => [
                ['domain' => 'example.com', 'selector' => 'selector1', 'algorithm' => 'rsa-sha256'],
                ['domain' => 'example.com', 'selector' => 'selector2', 'algorithm' => 'ed25519-sha256'],
            ],
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_INVALID, $result->getStatus());
        self::assertStringContainsString('selector2._domainkey.example.com', $result->getMessage());
    }

    /**
     * Backward compatibility: singular dkim/dkim_signature still works
     * when dkim_results/dkim_signatures are not present.
     */
    public function testBackwardCompatSingularKeys(): void
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
                // No dkim_results key
            ],
            // No dkim_signatures key
        ];

        $result = $validator->validate('test@example.com', 'example.com', $emlData);

        self::assertSame(ValidationResult::STATUS_VALID, $result->getStatus());
    }
}
