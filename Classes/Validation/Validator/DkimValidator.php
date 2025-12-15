<?php

declare(strict_types=1);

namespace Hn\MailSender\Validation\Validator;

use Hn\MailSender\Validation\SenderAddressValidatorInterface;
use Hn\MailSender\Validation\ValueObject\ValidationResult;

/**
 * DKIM Validator
 *
 * Validates DKIM (DomainKeys Identified Mail) authentication results.
 *
 * Unlike SPF and DMARC which can be partially validated via DNS,
 * DKIM validation requires the actual email to verify the signature.
 * This validator only works when EML data is available.
 *
 * When no EML file is uploaded, returns a warning prompting the user
 * to upload a test email for complete validation.
 */
class DkimValidator implements SenderAddressValidatorInterface
{
    public function validate(string $email, string $domain, ?array $emlData = null): ValidationResult
    {
        // DKIM can only be validated from actual email
        if ($emlData === null) {
            return ValidationResult::warning(
                'DKIM validation requires uploaded test email',
                [
                    'warnings' => ['Upload a received test email (.eml) to validate DKIM signature'],
                    'info' => 'DKIM signatures can only be verified from actual received emails',
                ]
            );
        }

        $authResults = $emlData['authentication_results'] ?? [];
        $dkimResult = $authResults['dkim'] ?? null;

        if ($dkimResult === null) {
            // Fallback: Try to verify DKIM signature ourselves
            $dkimSignature = $emlData['dkim_signature'] ?? null;
            if ($dkimSignature !== null) {
                return $this->validateFromDkimSignature($dkimSignature, $domain, $emlData);
            }

            return ValidationResult::warning(
                'No DKIM result found in received email',
                [
                    'warnings' => ['The received email does not contain a DKIM authentication result'],
                    'source' => 'eml',
                    'eml_file_hash' => $emlData['file_hash'] ?? '',
                    'info' => 'Email may not have been DKIM signed, or receiving server did not report result',
                ]
            );
        }

        $result = strtolower($dkimResult['result'] ?? '');
        $selector = $dkimResult['selector'] ?? null;
        $signingDomain = $dkimResult['domain'] ?? null;

        // Fetch current DKIM key from DNS for drift detection
        $currentDkimKey = null;
        $dkimDnsRecord = null;
        if ($selector !== null && $signingDomain !== null) {
            $dkimDnsRecord = $selector . '._domainkey.' . $signingDomain;
            $currentDkimKey = $this->fetchDkimKey($dkimDnsRecord);
        }

        // Check for drift
        $previousKey = $emlData['previous_validation']['DKIM Validator']['dkim_public_key'] ?? null;
        $dnsChanged = $previousKey !== null && $previousKey !== $currentDkimKey;

        $details = [
            'source' => 'eml',
            'eml_file_hash' => $emlData['file_hash'] ?? '',
            'eml_result' => $result,
            'dkim_selector' => $selector,
            'dkim_domain' => $signingDomain,
            'dkim_dns_record' => $dkimDnsRecord,
            'dkim_public_key' => $currentDkimKey,
            'dns_changed' => $dnsChanged,
            'dkim_details' => $dkimResult['details'] ?? [],
        ];

        // Check domain alignment
        $domainAligned = false;
        if ($signingDomain !== null) {
            // Relaxed alignment: signing domain can be subdomain or same as sender domain
            $domainAligned = $signingDomain === $domain
                || str_ends_with($signingDomain, '.' . $domain)
                || str_ends_with($domain, '.' . $signingDomain);
        }
        $details['domain_aligned'] = $domainAligned;

        return match ($result) {
            'pass' => $this->handlePassResult($domainAligned, $signingDomain, $domain, $currentDkimKey, $dnsChanged, $details),
            'fail' => ValidationResult::invalid(
                'DKIM authentication failed - signature invalid',
                ['errors' => ['DKIM signature verification failed'], ...$details]
            ),
            'neutral' => ValidationResult::warning(
                'DKIM result neutral - signature not verified',
                ['warnings' => ['DKIM returned neutral result'], ...$details]
            ),
            'none' => ValidationResult::warning(
                'No DKIM signature found in email',
                ['warnings' => ['Email was not DKIM signed'], ...$details]
            ),
            'temperror', 'permerror' => ValidationResult::warning(
                'DKIM check encountered an error: ' . $result,
                ['warnings' => ['DKIM error: ' . $result], ...$details]
            ),
            default => ValidationResult::warning(
                'Unknown DKIM result: ' . $result,
                ['warnings' => ['Unknown DKIM result'], ...$details]
            ),
        };
    }

    /**
     * Fetch DKIM public key from DNS
     */
    protected function fetchDkimKey(string $dkimDnsRecord): ?string
    {
        $dnsResult = @dns_get_record($dkimDnsRecord, DNS_TXT);
        if (empty($dnsResult)) {
            return null;
        }

        foreach ($dnsResult as $record) {
            $txt = $record['txt'] ?? '';
            if (str_contains($txt, 'v=DKIM1') || str_contains($txt, 'p=')) {
                return $txt;
            }
        }

        return null;
    }

    /**
     * Validate DKIM by checking if the signing key exists in DNS
     *
     * This is a fallback when Authentication-Results header is not available.
     * We cannot actually verify the signature (which requires full message canonicalization),
     * but we can verify that:
     * 1. The DKIM signature header exists
     * 2. The public key record exists in DNS
     * 3. The signing domain aligns with the sender domain
     */
    private function validateFromDkimSignature(array $dkimSignature, string $senderDomain, array $emlData): ValidationResult
    {
        $signingDomain = $dkimSignature['domain'] ?? null;
        $selector = $dkimSignature['selector'] ?? null;
        $fileHash = $emlData['file_hash'] ?? '';

        if ($signingDomain === null || $selector === null) {
            return ValidationResult::warning(
                'DKIM signature incomplete - missing domain or selector',
                [
                    'warnings' => ['DKIM signature present but missing required fields'],
                    'source' => 'eml_signature',
                    'eml_file_hash' => $fileHash,
                ]
            );
        }

        // Check domain alignment
        $domainAligned = $signingDomain === $senderDomain
            || str_ends_with($signingDomain, '.' . $senderDomain)
            || str_ends_with($senderDomain, '.' . $signingDomain);

        // Query DNS for DKIM public key
        $dkimDnsRecord = $selector . '._domainkey.' . $signingDomain;
        $currentDkimKey = $this->fetchDkimKey($dkimDnsRecord);

        // Check for drift
        $previousKey = $emlData['previous_validation']['DKIM Validator']['dkim_public_key'] ?? null;
        $dnsChanged = $previousKey !== null && $previousKey !== $currentDkimKey;

        $details = [
            'source' => 'eml_signature',
            'eml_file_hash' => $fileHash,
            'dkim_selector' => $selector,
            'dkim_domain' => $signingDomain,
            'dkim_dns_record' => $dkimDnsRecord,
            'dkim_public_key' => $currentDkimKey,
            'dns_changed' => $dnsChanged,
            'domain_aligned' => $domainAligned,
            'algorithm' => $dkimSignature['algorithm'] ?? null,
            'headers_signed' => $dkimSignature['headers_signed'] ?? [],
        ];

        if ($currentDkimKey === null) {
            return ValidationResult::invalid(
                'DKIM public key not found in DNS',
                [
                    'errors' => ['No DKIM record found at ' . $dkimDnsRecord],
                    'info' => 'The email has a DKIM signature but the public key is not published in DNS',
                    ...$details,
                ]
            );
        }

        // Key exists - check for drift warning
        if ($dnsChanged) {
            return ValidationResult::warning(
                'DKIM key has changed since test email was uploaded',
                [
                    'warnings' => ['DKIM public key changed. Upload new test email to verify current configuration.'],
                    'info' => 'DKIM signature found, but DNS key differs from previous validation.',
                    ...$details,
                ]
            );
        }

        // Key exists - we can't verify the signature without full message canonicalization
        // but the presence of the key indicates DKIM is properly configured
        // Domain alignment is DMARC's concern, not DKIM's
        if (!$domainAligned) {
            $details['info'] = 'DKIM signed by "' . $signingDomain . '" (domain alignment is checked by DMARC). Note: Full signature verification not performed.';
        } else {
            $details['info'] = 'DKIM signature found, DNS key verified. Note: Full signature verification not performed.';
        }

        return ValidationResult::valid(
            'DKIM signature present and public key exists in DNS',
            $details
        );
    }

    /**
     * Handle DKIM pass result with domain alignment and drift check
     */
    private function handlePassResult(
        bool $domainAligned,
        ?string $signingDomain,
        string $senderDomain,
        ?string $currentDkimKey,
        bool $dnsChanged,
        array $details
    ): ValidationResult {
        // Check for key removal (key existed when EML was uploaded but now gone)
        if ($currentDkimKey === null && $details['dkim_dns_record'] !== null) {
            return ValidationResult::warning(
                'DKIM passed in test email, but key no longer exists in DNS',
                [
                    'warnings' => ['DKIM public key has been removed from DNS. Upload new test email to verify current configuration.'],
                    ...$details,
                ]
            );
        }

        // Check for drift
        if ($dnsChanged) {
            return ValidationResult::warning(
                'DKIM passed in test email, but DNS key has changed',
                [
                    'warnings' => ['DKIM public key changed since test email was uploaded. Upload new test email to verify current configuration.'],
                    ...$details,
                ]
            );
        }

        // Domain alignment is DMARC's concern, not DKIM's
        // If DKIM passed, that's what matters for DKIM validation
        if (!$domainAligned && $signingDomain !== null) {
            $details['info'] = 'DKIM signed by "' . $signingDomain . '" (domain alignment is checked by DMARC)';
        }

        return ValidationResult::valid(
            'DKIM authentication passed (verified from received email)',
            $details
        );
    }

    public function getName(): string
    {
        return 'DKIM Validator';
    }

    public function getPriority(): int
    {
        return 13; // After DMARC (11), SPF (12), before EmailExistence (20)
    }
}
