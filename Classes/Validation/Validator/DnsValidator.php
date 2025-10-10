<?php

declare(strict_types=1);

namespace Hn\MailSender\Validation\Validator;

use Hn\MailSender\Validation\SenderAddressValidatorInterface;
use Hn\MailSender\Validation\ValueObject\ValidationResult;

/**
 * DNS Validator
 *
 * Validates sender addresses by checking DNS records:
 * - MX records (mail exchange)
 * - SPF records (sender policy framework)
 * - DMARC records (domain-based message authentication)
 */
class DnsValidator implements SenderAddressValidatorInterface
{
    public function validate(string $email, string $domain): ValidationResult
    {
        $details = [];
        $errors = [];
        $warnings = [];

        // Check MX records
        $mxRecords = @dns_get_record($domain, DNS_MX);
        if ($mxRecords === false || empty($mxRecords)) {
            $errors[] = 'No MX records found for domain';
        } else {
            $details['mx_records'] = array_map(
                fn($record) => [
                    'host' => $record['target'] ?? '',
                    'priority' => $record['pri'] ?? 0,
                ],
                $mxRecords
            );
        }

        // Check SPF records
        $spfRecord = $this->checkSpfRecord($domain);
        if ($spfRecord !== null) {
            $details['spf_record'] = $spfRecord;
        } else {
            $warnings[] = 'No SPF record found (recommended for email authentication)';
        }

        // Check DMARC records
        $dmarcRecord = $this->checkDmarcRecord($domain);
        if ($dmarcRecord !== null) {
            $details['dmarc_record'] = $dmarcRecord;
        } else {
            $warnings[] = 'No DMARC record found (recommended for email authentication)';
        }

        // Determine overall status
        if (!empty($errors)) {
            return ValidationResult::invalid(
                'DNS validation failed: ' . implode(', ', $errors),
                ['errors' => $errors, 'warnings' => $warnings, ...$details]
            );
        }

        if (!empty($warnings)) {
            return ValidationResult::warning(
                'DNS validation passed with warnings: ' . implode(', ', $warnings),
                ['warnings' => $warnings, ...$details]
            );
        }

        return ValidationResult::valid(
            'DNS validation passed',
            $details
        );
    }

    /**
     * Check for SPF record
     *
     * @return array<string, mixed>|null SPF record details or null if not found
     */
    private function checkSpfRecord(string $domain): ?array
    {
        $txtRecords = @dns_get_record($domain, DNS_TXT);
        if ($txtRecords === false) {
            return null;
        }

        foreach ($txtRecords as $record) {
            $txt = $record['txt'] ?? '';
            if (str_starts_with($txt, 'v=spf1')) {
                return [
                    'record' => $txt,
                    'found' => true,
                ];
            }
        }

        return null;
    }

    /**
     * Check for DMARC record
     *
     * @return array<string, mixed>|null DMARC record details or null if not found
     */
    private function checkDmarcRecord(string $domain): ?array
    {
        $dmarcDomain = '_dmarc.' . $domain;
        $txtRecords = @dns_get_record($dmarcDomain, DNS_TXT);

        if ($txtRecords === false) {
            return null;
        }

        foreach ($txtRecords as $record) {
            $txt = $record['txt'] ?? '';
            if (str_starts_with($txt, 'v=DMARC1')) {
                return [
                    'record' => $txt,
                    'found' => true,
                ];
            }
        }

        return null;
    }

    public function getName(): string
    {
        return 'DNS Validator';
    }

    public function getPriority(): int
    {
        return 10;
    }
}
