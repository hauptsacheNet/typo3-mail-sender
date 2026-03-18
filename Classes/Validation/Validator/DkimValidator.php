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
 * When multiple DKIM signatures are present (e.g., RSA + Ed25519),
 * this validator checks all of them. If the mail server only verified
 * some signatures, the validator performs its own DNS key lookup for
 * the remaining ones.
 */
class DkimValidator implements SenderAddressValidatorInterface
{
    public function validate(string $email, string $domain, ?array $emlData = null): ValidationResult
    {
        // DKIM can only be validated from actual email
        if ($emlData === null) {
            return ValidationResult::skipped(
                'DKIM validation requires uploaded test email',
                [
                    'info' => 'Upload a received test email (.eml) to validate DKIM signature',
                ]
            );
        }

        $authResults = $emlData['authentication_results'] ?? [];
        $dkimResults = $authResults['dkim_results'] ?? [];

        // Backward compat: wrap singular dkim result if dkim_results is empty
        if (empty($dkimResults) && !empty($authResults['dkim'])) {
            $dkimResults = [$authResults['dkim']];
        }

        // Get all DKIM signatures from the email
        $dkimSignatures = $emlData['dkim_signatures'] ?? [];
        if (empty($dkimSignatures) && !empty($emlData['dkim_signature'])) {
            $dkimSignatures = [$emlData['dkim_signature']];
        }

        // No DKIM info at all — fallback or warning
        if (empty($dkimResults) && empty($dkimSignatures)) {
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

        // No auth results but we have signatures — do DNS key verification ourselves
        if (empty($dkimResults) && !empty($dkimSignatures)) {
            return $this->validateFromSignaturesOnly($dkimSignatures, $domain, $emlData);
        }

        // We have auth results — process them
        return $this->validateFromAuthResults($dkimResults, $dkimSignatures, $domain, $emlData);
    }

    /**
     * Validate when we have Authentication-Results from the mail server.
     * Also cross-check DKIM signatures that the server didn't verify.
     */
    private function validateFromAuthResults(array $dkimResults, array $dkimSignatures, string $domain, array $emlData): ValidationResult
    {
        $fileHash = $emlData['file_hash'] ?? '';
        $hasPass = false;
        $hasFail = false;
        $passResult = null;

        foreach ($dkimResults as $dr) {
            $result = strtolower($dr['result'] ?? '');
            if ($result === 'pass') {
                $hasPass = true;
                $passResult = $dr;
            } elseif ($result === 'fail') {
                $hasFail = true;
            }
        }

        // Determine the primary result (the passing one, or the first one)
        $primaryResult = $passResult ?? $dkimResults[0];
        $primaryStatus = strtolower($primaryResult['result'] ?? '');
        $selector = $primaryResult['selector'] ?? null;
        $signingDomain = $primaryResult['domain'] ?? null;

        // Fetch current DKIM key from DNS for drift detection on primary signature
        $currentDkimKey = null;
        $dkimDnsRecord = null;
        if ($selector !== null && $signingDomain !== null) {
            $dkimDnsRecord = $selector . '._domainkey.' . $signingDomain;
            $currentDkimKey = $this->fetchDkimKey($dkimDnsRecord);
        }

        // Check for drift
        $previousKey = $emlData['previous_validation']['DKIM Validator']['dkim_public_key'] ?? null;
        $dnsChanged = $previousKey !== null && $previousKey !== $currentDkimKey;

        // Check domain alignment
        $domainAligned = false;
        if ($signingDomain !== null) {
            $domainAligned = $signingDomain === $domain
                || str_ends_with($signingDomain, '.' . $domain)
                || str_ends_with($domain, '.' . $signingDomain);
        }

        // Verify DNS keys for signatures the mail server didn't fully verify
        $uncheckedSignatures = $this->findUncheckedSignatures($dkimResults, $dkimSignatures);
        $missingKeys = [];
        $verifiedKeys = [];
        foreach ($uncheckedSignatures as $sig) {
            $sigSelector = $sig['selector'] ?? null;
            $sigDomain = $sig['domain'] ?? null;
            if ($sigSelector === null || $sigDomain === null) {
                continue;
            }
            $record = $sigSelector . '._domainkey.' . $sigDomain;
            $key = $this->fetchDkimKey($record);
            if ($key === null) {
                $missingKeys[] = $record;
            } else {
                $verifiedKeys[] = $record;
            }
        }

        $details = [
            'source' => 'eml',
            'eml_file_hash' => $fileHash,
            'eml_result' => $primaryStatus,
            'dkim_selector' => $selector,
            'dkim_domain' => $signingDomain,
            'dkim_dns_record' => $dkimDnsRecord,
            'dkim_public_key' => $currentDkimKey,
            'dns_changed' => $dnsChanged,
            'dkim_details' => $primaryResult['details'] ?? [],
            'domain_aligned' => $domainAligned,
            'all_dkim_results' => $dkimResults,
            'self_verified_keys' => $verifiedKeys,
            'missing_keys' => $missingKeys,
        ];

        // If any result passed, this is valid (with drift/key-removal checks)
        if ($hasPass) {
            return $this->handlePassResult($domainAligned, $signingDomain, $domain, $currentDkimKey, $dnsChanged, $details);
        }

        // No pass — if only fail(s) and no pass, it's invalid
        if ($hasFail) {
            return ValidationResult::invalid(
                'DKIM authentication failed - signature invalid',
                ['errors' => ['DKIM signature verification failed'], ...$details]
            );
        }

        // No pass, no fail — neutral/none/temperror. Check DNS keys ourselves.
        if (empty($missingKeys)) {
            // All DNS keys exist — the mail server just didn't verify them
            $details['info'] = 'Mail server did not fully verify DKIM signatures, but all DNS keys are present.';
            return ValidationResult::valid(
                'DKIM DNS keys verified (mail server reported neutral)',
                $details
            );
        }

        // Some keys missing
        return ValidationResult::warning(
            'DKIM result neutral and some DNS keys are missing',
            [
                'warnings' => ['DKIM returned neutral result and DNS key(s) missing: ' . implode(', ', $missingKeys)],
                ...$details,
            ]
        );
    }

    /**
     * Find DKIM signatures that don't have a matching 'pass' in the auth results.
     * Matches by selector+domain when available.
     */
    private function findUncheckedSignatures(array $dkimResults, array $dkimSignatures): array
    {
        // Build a set of selector+domain combos that passed
        $passedCombos = [];
        foreach ($dkimResults as $dr) {
            if (strtolower($dr['result'] ?? '') === 'pass' && !empty($dr['selector']) && !empty($dr['domain'])) {
                $passedCombos[] = strtolower($dr['selector']) . '/' . strtolower($dr['domain']);
            }
        }

        $unchecked = [];
        foreach ($dkimSignatures as $sig) {
            $sigSelector = $sig['selector'] ?? null;
            $sigDomain = $sig['domain'] ?? null;
            if ($sigSelector === null || $sigDomain === null) {
                continue;
            }
            $combo = strtolower($sigSelector) . '/' . strtolower($sigDomain);
            if (!in_array($combo, $passedCombos, true)) {
                $unchecked[] = $sig;
            }
        }

        return $unchecked;
    }

    /**
     * Validate when we only have DKIM-Signature headers (no Authentication-Results).
     * Checks DNS for all signatures.
     */
    private function validateFromSignaturesOnly(array $dkimSignatures, string $senderDomain, array $emlData): ValidationResult
    {
        $fileHash = $emlData['file_hash'] ?? '';
        $allKeysExist = true;
        $missingRecords = [];
        $firstSignature = $dkimSignatures[0];
        $primarySelector = $firstSignature['selector'] ?? null;
        $primaryDomain = $firstSignature['domain'] ?? null;
        $primaryDnsRecord = null;
        $primaryKey = null;

        foreach ($dkimSignatures as $sig) {
            $sigDomain = $sig['domain'] ?? null;
            $sigSelector = $sig['selector'] ?? null;
            if ($sigDomain === null || $sigSelector === null) {
                continue;
            }
            $dnsRecord = $sigSelector . '._domainkey.' . $sigDomain;
            $key = $this->fetchDkimKey($dnsRecord);
            if ($key === null) {
                $allKeysExist = false;
                $missingRecords[] = $dnsRecord;
            }
            // Track the primary signature's key for drift detection
            if ($sigSelector === $primarySelector && $sigDomain === $primaryDomain) {
                $primaryDnsRecord = $dnsRecord;
                $primaryKey = $key;
            }
        }

        // Check domain alignment on primary signature
        $domainAligned = false;
        if ($primaryDomain !== null) {
            $domainAligned = $primaryDomain === $senderDomain
                || str_ends_with($primaryDomain, '.' . $senderDomain)
                || str_ends_with($senderDomain, '.' . $primaryDomain);
        }

        // Check for drift
        $previousKey = $emlData['previous_validation']['DKIM Validator']['dkim_public_key'] ?? null;
        $dnsChanged = $previousKey !== null && $previousKey !== $primaryKey;

        $details = [
            'source' => 'eml_signature',
            'eml_file_hash' => $fileHash,
            'dkim_selector' => $primarySelector,
            'dkim_domain' => $primaryDomain,
            'dkim_dns_record' => $primaryDnsRecord,
            'dkim_public_key' => $primaryKey,
            'dns_changed' => $dnsChanged,
            'domain_aligned' => $domainAligned,
            'algorithm' => $firstSignature['algorithm'] ?? null,
            'headers_signed' => $firstSignature['headers_signed'] ?? [],
            'all_signatures_count' => count($dkimSignatures),
            'missing_keys' => $missingRecords,
        ];

        if (!$allKeysExist) {
            return ValidationResult::invalid(
                'DKIM public key not found in DNS for: ' . implode(', ', $missingRecords),
                [
                    'errors' => array_map(fn ($r) => 'No DKIM record found at ' . $r, $missingRecords),
                    'info' => 'The email has DKIM signature(s) but some public key(s) are not published in DNS',
                    ...$details,
                ]
            );
        }

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

        if (!$domainAligned) {
            $details['info'] = 'DKIM signed by "' . $primaryDomain . '" (domain alignment is checked by DMARC). Note: Full signature verification not performed.';
        } else {
            $details['info'] = 'DKIM signature(s) found, all DNS keys verified. Note: Full signature verification not performed.';
        }

        return ValidationResult::valid(
            'DKIM signature(s) present and public key(s) exist in DNS',
            $details
        );
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
