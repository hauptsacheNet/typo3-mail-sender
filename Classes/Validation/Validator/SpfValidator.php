<?php

declare(strict_types=1);

namespace Hn\MailSender\Validation\Validator;

use Hn\MailSender\Configuration\MailConfigurationProvider;
use Hn\MailSender\Validation\SenderAddressValidatorInterface;
use Hn\MailSender\Validation\ValueObject\ValidationResult;
use Mika56\SPFCheck\DNS\DNSRecordGetter;
use Mika56\SPFCheck\Model\Result;
use Mika56\SPFCheck\SPFCheck;

/**
 * SPF Validator
 *
 * Validates that the configured SMTP server is authorized to send
 * emails for the given domain according to its SPF record.
 *
 * If the mika56/spfcheck library is available, performs full RFC 7208
 * compliant SPF validation. Otherwise, falls back to basic SPF record
 * existence check.
 */
class SpfValidator implements SenderAddressValidatorInterface
{
    public function __construct(
        private readonly MailConfigurationProvider $configurationProvider
    ) {
    }

    public function validate(string $email, string $domain, ?array $emlData = null): ValidationResult
    {
        // Always fetch current SPF record for drift detection
        $currentSpfRecord = $this->fetchSpfRecord($domain);

        // Get DNS-based validation result
        $dnsResult = $this->isSpfLibraryAvailable()
            ? $this->validateWithLibrary($domain)
            : $this->validateBasicSpfRecord($domain);

        // Check for drift by comparing with previous validation
        $previousRecord = $emlData['previous_validation']['SPF Validator']['spf_record'] ?? null;
        $dnsChanged = $previousRecord !== null && $previousRecord !== $currentSpfRecord;

        // If EML data with SPF result is available, combine with DNS result
        if ($emlData !== null) {
            return $this->validateWithEmlAndDns($emlData, $domain, $currentSpfRecord, $dnsResult, $dnsChanged);
        }

        // No EML, return DNS-only result
        return $dnsResult;
    }

    /**
     * Validate using both EML Authentication-Results and current DNS state
     */
    private function validateWithEmlAndDns(
        array $emlData,
        string $domain,
        ?string $currentSpfRecord,
        ValidationResult $dnsResult,
        bool $dnsChanged
    ): ValidationResult {
        $authResults = $emlData['authentication_results'] ?? [];
        $spfResult = $authResults['spf'] ?? null;

        $details = [
            'spf_record' => $currentSpfRecord,
            'dns_result' => $dnsResult->getStatus(),
            'dns_message' => $dnsResult->getMessage(),
            'dns_changed' => $dnsChanged,
            'eml_file_hash' => $emlData['file_hash'] ?? '',
        ];

        // No SPF result in EML, fall back to DNS validation
        if ($spfResult === null) {
            return $dnsResult;
        }

        $emlSpfResult = strtolower($spfResult['result'] ?? '');
        $details['eml_result'] = $emlSpfResult;
        $details['spf_details'] = $spfResult['details'] ?? [];

        // Handle different EML results
        if ($emlSpfResult === 'pass') {
            if ($dnsChanged) {
                return ValidationResult::warning(
                    'SPF passed in test email, but DNS record has changed',
                    [
                        'warnings' => ['SPF record changed since test email was uploaded. Upload new test email to verify current configuration.'],
                        'previous_spf_record' => $emlData['previous_validation']['SPF Validator']['spf_record'] ?? null,
                        ...$details,
                    ]
                );
            }
            return ValidationResult::valid(
                'SPF authentication passed (verified from received email)',
                $details
            );
        }

        if (in_array($emlSpfResult, ['fail', 'hardfail'], true)) {
            return ValidationResult::invalid(
                'SPF authentication failed (verified from received email)',
                ['errors' => ['SPF check failed: ' . $emlSpfResult], ...$details]
            );
        }

        if ($emlSpfResult === 'softfail') {
            return ValidationResult::warning(
                'SPF soft fail - emails may be marked as suspicious',
                ['warnings' => ['SPF softfail - sender may not be authorized'], ...$details]
            );
        }

        // neutral, none, temperror, permerror, or unknown
        return ValidationResult::warning(
            'SPF result: ' . $emlSpfResult,
            ['warnings' => ['SPF returned ' . $emlSpfResult], ...$details]
        );
    }

    /**
     * Check if the mika56/spfcheck library is available
     */
    private function isSpfLibraryAvailable(): bool
    {
        return class_exists(SPFCheck::class)
            && class_exists(DNSRecordGetter::class)
            && class_exists(Result::class);
    }

    /**
     * Validate SPF using the mika56/spfcheck library
     */
    private function validateWithLibrary(string $domain): ValidationResult
    {
        $details = [];

        // Check if SMTP is configured
        if (!$this->configurationProvider->isSmtpConfigured()) {
            return ValidationResult::skipped(
                'SPF validation skipped: SMTP transport not configured',
                ['reason' => 'no_smtp_transport']
            );
        }

        // Get SMTP server IPs
        $smtpHost = $this->configurationProvider->getSmtpServerHost();
        $smtpIps = $this->configurationProvider->getSmtpServerIps();

        if (empty($smtpIps)) {
            return ValidationResult::skipped(
                'SPF validation skipped: Cannot resolve SMTP server IP',
                ['reason' => 'smtp_ip_unresolved', 'smtp_host' => $smtpHost]
            );
        }

        $details['smtp_host'] = $smtpHost;
        $details['smtp_ips'] = $smtpIps;

        // Fetch and include the SPF record for display
        $spfRecord = $this->fetchSpfRecord($domain);
        if ($spfRecord !== null) {
            $details['spf_record'] = $spfRecord;
        }

        // Create SPF checker
        $checker = new SPFCheck(new DNSRecordGetter());

        // Check each SMTP IP against SPF
        $results = [];
        $anyPass = false;
        $allFail = true;
        $failingIps = [];

        foreach ($smtpIps as $ip) {
            $result = $checker->getIPStringResult($ip, $domain);
            $results[$ip] = $this->getReadableResult($result);

            if ($result === Result::SHORT_PASS) {
                $anyPass = true;
                $allFail = false;
            } elseif ($result === Result::SHORT_FAIL || $result === Result::SHORT_SOFTFAIL) {
                $failingIps[] = $ip;
            } else {
                // Neutral, None, Permerror, Temperror - not a fail
                $allFail = false;
            }
        }

        $details['spf_results'] = $results;

        // Determine overall result
        if ($anyPass) {
            if (!empty($failingIps)) {
                // Some IPs pass, some fail - this is a configuration error
                // Even though some IPs are authorized, having unauthorized IPs indicates misconfiguration
                return ValidationResult::invalid(
                    'SPF validation failed: Not all SMTP server IPs are authorized',
                    [
                        'errors' => ['IP(s) ' . implode(', ', $failingIps) . ' not authorized by SPF'],
                        'info' => 'If your SMTP uses a relay service, upload a received test email (.eml) to verify actual delivery.',
                        ...$details
                    ]
                );
            }
            return ValidationResult::valid(
                'SPF validation passed: SMTP server is authorized to send for this domain',
                $details
            );
        }

        // No IP passed - check if all failed or if results were inconclusive
        if ($allFail && !empty($failingIps)) {
            return ValidationResult::invalid(
                'SPF validation failed: SMTP server is not authorized to send for this domain',
                [
                    'errors' => ['SMTP server IP(s) ' . implode(', ', $failingIps) . ' not authorized by SPF'],
                    ...$details
                ]
            );
        }

        // Neutral, None, Permerror, Temperror for all IPs
        return ValidationResult::warning(
            'SPF validation inconclusive: Could not confirm SMTP server authorization',
            ['warnings' => ['SPF check returned neutral or error result for all IPs'], ...$details]
        );
    }

    /**
     * Convert short SPF result to human-readable name
     */
    private function getReadableResult(string $shortResult): string
    {
        return match ($shortResult) {
            Result::SHORT_PASS => Result::PASS,
            Result::SHORT_FAIL => Result::FAIL,
            Result::SHORT_SOFTFAIL => Result::SOFTFAIL,
            Result::SHORT_NEUTRAL => Result::NEUTRAL,
            Result::SHORT_NONE => Result::NONE,
            Result::SHORT_TEMPERROR => Result::TEMPERROR,
            Result::SHORT_PERMERROR => Result::PERMERROR,
            default => $shortResult,
        };
    }

    /**
     * Fetch the SPF record for a domain
     */
    protected function fetchSpfRecord(string $domain): ?string
    {
        $txtRecords = @dns_get_record($domain, DNS_TXT);

        if ($txtRecords === false) {
            return null;
        }

        foreach ($txtRecords as $record) {
            $txt = $record['txt'] ?? '';
            if (str_starts_with($txt, 'v=spf1')) {
                return $txt;
            }
        }

        return null;
    }

    /**
     * Basic SPF record check (fallback when library is not available)
     *
     * Only checks if an SPF record exists, cannot validate if the SMTP
     * server is actually authorized.
     */
    private function validateBasicSpfRecord(string $domain): ValidationResult
    {
        $txtRecords = @dns_get_record($domain, DNS_TXT);

        if ($txtRecords === false) {
            return ValidationResult::warning(
                'SPF validation limited: Could not retrieve DNS TXT records',
                [
                    'reason' => 'dns_lookup_failed',
                    'library_available' => false,
                ]
            );
        }

        $spfRecord = null;
        foreach ($txtRecords as $record) {
            $txt = $record['txt'] ?? '';
            if (str_starts_with($txt, 'v=spf1')) {
                $spfRecord = $txt;
                break;
            }
        }

        if ($spfRecord === null) {
            return ValidationResult::warning(
                'No SPF record found for domain',
                [
                    'warnings' => ['No SPF record found (recommended for email authentication)'],
                    'library_available' => false,
                ]
            );
        }

        // SPF record exists but we can't verify SMTP authorization without the library
        return ValidationResult::warning(
            'SPF record found but authorization check requires mika56/spfcheck library',
            [
                'spf_record' => $spfRecord,
                'library_available' => false,
                'info' => 'Install mika56/spfcheck via composer for full SPF authorization validation',
            ]
        );
    }

    public function getName(): string
    {
        return 'SPF Validator';
    }

    public function getPriority(): int
    {
        return 12;
    }
}
