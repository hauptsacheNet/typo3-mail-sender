<?php

declare(strict_types=1);

namespace Hn\MailSender\Validation\Validator;

use Hn\MailSender\Validation\SenderAddressValidatorInterface;
use Hn\MailSender\Validation\ValueObject\ValidationResult;

/**
 * MX Validator
 *
 * Validates that the domain has MX (Mail Exchange) records configured,
 * which indicates the domain can receive email and has mail servers set up.
 */
class MxValidator implements SenderAddressValidatorInterface
{
    public function validate(string $email, string $domain): ValidationResult
    {
        $mxRecords = @dns_get_record($domain, DNS_MX);

        if ($mxRecords === false) {
            return ValidationResult::invalid(
                'MX validation failed: Could not query DNS records',
                ['errors' => ['DNS lookup failed for domain']]
            );
        }

        if (empty($mxRecords)) {
            return ValidationResult::invalid(
                'MX validation failed: No MX records found for domain',
                ['errors' => ['No mail servers configured for this domain']]
            );
        }

        $details = [
            'mx_records' => array_map(
                fn($record) => [
                    'host' => $record['target'] ?? '',
                    'priority' => $record['pri'] ?? 0,
                ],
                $mxRecords
            ),
        ];

        return ValidationResult::valid(
            'MX validation passed: Domain has mail servers configured',
            $details
        );
    }

    public function getName(): string
    {
        return 'MX Validator';
    }

    public function getPriority(): int
    {
        return 10;
    }
}
