<?php

declare(strict_types=1);

namespace Hn\MailSender\Validation\Validator;

use Hn\MailSender\Validation\SenderAddressValidatorInterface;
use Hn\MailSender\Validation\ValueObject\ValidationResult;

/**
 * DMARC Validator
 *
 * Validates DMARC (Domain-based Message Authentication, Reporting & Conformance)
 * configuration for the sender domain. DMARC builds on SPF and DKIM to provide
 * email authentication policy and reporting.
 *
 * Checks:
 * - DMARC record exists at _dmarc.<domain>
 * - Record syntax is valid (starts with v=DMARC1)
 * - Policy strength (p=none/quarantine/reject)
 * - Alignment settings (aspf, adkim)
 * - Reporting configuration (rua, ruf)
 */
class DmarcValidator implements SenderAddressValidatorInterface
{
    public function validate(string $email, string $domain, ?array $emlData = null): ValidationResult
    {
        // Always fetch current DMARC record for drift detection
        $dmarcDomain = '_dmarc.' . $domain;
        $currentDmarcRecord = $this->fetchDmarcRecord($dmarcDomain);

        // Get DNS-based validation result
        $dnsResult = $this->validateDnsRecord($domain, $dmarcDomain, $currentDmarcRecord);

        // Check for drift by comparing with previous validation
        $previousRecord = $emlData['previous_validation']['DMARC Validator']['dmarc_record'] ?? null;
        $dnsChanged = $previousRecord !== null && $previousRecord !== $currentDmarcRecord;

        // If EML data with DMARC result is available, combine with DNS result
        if ($emlData !== null) {
            return $this->validateWithEmlAndDns($emlData, $domain, $currentDmarcRecord, $dnsResult, $dnsChanged);
        }

        // No EML, return DNS-only result
        return $dnsResult;
    }

    /**
     * Fetch DMARC record from DNS
     */
    protected function fetchDmarcRecord(string $dmarcDomain): ?string
    {
        $txtRecords = @dns_get_record($dmarcDomain, DNS_TXT);
        if ($txtRecords === false) {
            return null;
        }

        foreach ($txtRecords as $record) {
            $txt = $record['txt'] ?? '';
            if (str_starts_with($txt, 'v=DMARC1')) {
                return $txt;
            }
        }

        return null;
    }

    /**
     * Validate using both EML Authentication-Results and current DNS state
     */
    private function validateWithEmlAndDns(
        array $emlData,
        string $domain,
        ?string $currentDmarcRecord,
        ValidationResult $dnsResult,
        bool $dnsChanged
    ): ValidationResult {
        $authResults = $emlData['authentication_results'] ?? [];
        $dmarcResult = $authResults['dmarc'] ?? null;

        $details = [
            'dmarc_record' => $currentDmarcRecord,
            'dns_result' => $dnsResult->getStatus(),
            'dns_message' => $dnsResult->getMessage(),
            'dns_changed' => $dnsChanged,
            'eml_file_hash' => $emlData['file_hash'] ?? '',
        ];

        if ($currentDmarcRecord !== null) {
            $details['parsed'] = $this->parseDmarcRecord($currentDmarcRecord);
        }

        // No DMARC result in EML, fall back to DNS validation
        if ($dmarcResult === null) {
            return $dnsResult;
        }

        $emlDmarcResult = strtolower($dmarcResult['result'] ?? '');
        $details['eml_result'] = $emlDmarcResult;
        $details['dmarc_details'] = $dmarcResult['details'] ?? [];

        // Handle different EML results
        if ($emlDmarcResult === 'pass') {
            if ($dnsChanged) {
                return ValidationResult::warning(
                    'DMARC passed in test email, but DNS record has changed',
                    [
                        'warnings' => ['DMARC record changed since test email was uploaded. Upload new test email to verify current configuration.'],
                        'previous_dmarc_record' => $emlData['previous_validation']['DMARC Validator']['dmarc_record'] ?? null,
                        ...$details,
                    ]
                );
            }
            return ValidationResult::valid(
                'DMARC authentication passed (verified from received email)',
                $details
            );
        }

        if ($emlDmarcResult === 'fail') {
            return ValidationResult::invalid(
                'DMARC authentication failed (verified from received email)',
                ['errors' => ['DMARC check failed'], ...$details]
            );
        }

        // none, temperror, permerror, or unknown
        return ValidationResult::warning(
            'DMARC result: ' . $emlDmarcResult,
            ['warnings' => ['DMARC returned ' . $emlDmarcResult], ...$details]
        );
    }

    /**
     * Validate DMARC from DNS record
     */
    private function validateDnsRecord(string $domain, string $dmarcDomain, ?string $dmarcRecord): ValidationResult
    {
        if ($dmarcRecord === null) {
            return ValidationResult::warning(
                'No DMARC record found (recommended for email authentication)',
                [
                    'warnings' => ['DMARC policy not configured - emails may be less trusted'],
                    'recommendation' => 'Add a DMARC record at _dmarc.' . $domain,
                ]
            );
        }

        // Parse DMARC record
        $parsed = $this->parseDmarcRecord($dmarcRecord);
        $details = [
            'dmarc_record' => $dmarcRecord,
            'parsed' => $parsed,
        ];

        // Check for invalid syntax
        if (!isset($parsed['p'])) {
            return ValidationResult::invalid(
                'DMARC record invalid: Missing required policy (p=) tag',
                [
                    'errors' => ['DMARC record must contain a policy (p=) tag'],
                    ...$details,
                ]
            );
        }

        // Evaluate policy strength
        $warnings = [];
        $policy = $parsed['p'];

        switch ($policy) {
            case 'reject':
                // Strong policy - emails failing authentication are rejected
                break;
            case 'quarantine':
                // Moderate policy - emails failing authentication go to spam
                break;
            case 'none':
                $warnings[] = 'DMARC policy is set to "none" (monitoring only) - unauthorized emails are not blocked';
                break;
            default:
                return ValidationResult::invalid(
                    'DMARC record invalid: Unknown policy value "' . $policy . '"',
                    [
                        'errors' => ['Policy must be one of: none, quarantine, reject'],
                        ...$details,
                    ]
                );
        }

        // Check subdomain policy
        if (isset($parsed['sp'])) {
            $details['subdomain_policy'] = $parsed['sp'];
        }

        // Check alignment settings
        if (isset($parsed['aspf'])) {
            $details['spf_alignment'] = $parsed['aspf'] === 's' ? 'strict' : 'relaxed';
        }
        if (isset($parsed['adkim'])) {
            $details['dkim_alignment'] = $parsed['adkim'] === 's' ? 'strict' : 'relaxed';
        }

        // Check reporting
        if (isset($parsed['rua'])) {
            $details['aggregate_reports'] = $parsed['rua'];
        } else {
            $warnings[] = 'No aggregate report URI (rua) configured - you won\'t receive authentication reports';
        }
        if (isset($parsed['ruf'])) {
            $details['forensic_reports'] = $parsed['ruf'];
        }

        // Check percentage
        if (isset($parsed['pct'])) {
            $pct = (int)$parsed['pct'];
            $details['percentage'] = $pct;
            if ($pct < 100) {
                $warnings[] = 'DMARC policy only applies to ' . $pct . '% of emails';
            }
        }

        if (!empty($warnings)) {
            return ValidationResult::warning(
                'DMARC record found with recommendations',
                ['warnings' => $warnings, ...$details]
            );
        }

        return ValidationResult::valid(
            'DMARC validation passed: Policy "' . $policy . '" configured',
            $details
        );
    }

    /**
     * Parse DMARC record into key-value pairs
     *
     * @return array<string, string>
     */
    private function parseDmarcRecord(string $record): array
    {
        $parsed = [];
        $parts = explode(';', $record);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            $tagValue = explode('=', $part, 2);
            if (count($tagValue) === 2) {
                $parsed[trim($tagValue[0])] = trim($tagValue[1]);
            }
        }

        return $parsed;
    }

    public function getName(): string
    {
        return 'DMARC Validator';
    }

    public function getPriority(): int
    {
        return 11;
    }
}
