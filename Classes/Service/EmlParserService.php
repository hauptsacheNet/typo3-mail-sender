<?php

declare(strict_types=1);

namespace Hn\MailSender\Service;

use Hn\MailSender\Exception\EmlParserUnavailableException;
use TYPO3\CMS\Core\Resource\FileInterface;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * EML Parser Service
 *
 * Parses .eml files and extracts authentication-relevant headers
 * including Authentication-Results (SPF/DKIM/DMARC results from receiving server).
 */
class EmlParserService
{
    /**
     * Check if the mail-mime-parser library is available
     */
    public static function isAvailable(): bool
    {
        return class_exists(MailMimeParser::class);
    }

    /**
     * Parse an EML file and extract validation-relevant data
     *
     * @param FileInterface $file The EML file from FAL
     * @return array{
     *     file_hash: string,
     *     from: array{email: string, name: string},
     *     authentication_results: array{
     *         raw: string|null,
     *         spf: array{result: string, details: array}|null,
     *         dkim: array{result: string, selector: string|null, domain: string|null, details: array}|null,
     *         dmarc: array{result: string, details: array}|null
     *     },
     *     received_chain: array
     * }
     */
    public function parse(FileInterface $file): array
    {
        if (!self::isAvailable()) {
            throw new EmlParserUnavailableException(
                'The zbateson/mail-mime-parser library is required for EML parsing. '
                . 'Install it via Composer: composer require zbateson/mail-mime-parser',
                1735500000
            );
        }

        $content = $file->getContents();
        $fileHash = hash('sha256', $content);

        $parser = new MailMimeParser();
        $message = $parser->parse($content, true);

        // Extract From header using zbateson's address API
        $fromHeader = $message->getHeader('From');
        $from = [
            'email' => '',
            'name' => '',
        ];
        if ($fromHeader !== null && method_exists($fromHeader, 'getAddresses')) {
            $addresses = $fromHeader->getAddresses();
            if (!empty($addresses)) {
                $from['email'] = $addresses[0]->getEmail() ?? '';
                $from['name'] = $addresses[0]->getName() ?? '';
            }
        } elseif ($fromHeader !== null) {
            // Fallback to value parsing
            $fromValue = $fromHeader->getValue();
            if (preg_match('/^(.+?)\s*<([^>]+)>$/', $fromValue, $matches)) {
                $from['name'] = trim($matches[1], ' "\'');
                $from['email'] = $matches[2];
            } else {
                $from['email'] = trim($fromValue);
            }
        }

        // Extract Authentication-Results header
        $authResults = $this->parseAuthenticationResults($message);

        // Extract Received chain
        $receivedChain = [];
        $receivedHeaders = $message->getAllHeaders();
        foreach ($receivedHeaders as $header) {
            if (strtolower($header->getName()) === 'received') {
                $receivedChain[] = $header->getValue();
            }
        }

        // Extract DKIM signatures (for fallback verification if Authentication-Results not available)
        $dkimSignature = $this->parseDkimSignature($message);
        $dkimSignatures = $this->parseDkimSignatures($message);

        return [
            'file_hash' => $fileHash,
            'from' => $from,
            'authentication_results' => $authResults,
            'dkim_signature' => $dkimSignature,
            'dkim_signatures' => $dkimSignatures,
            'received_chain' => $receivedChain,
        ];
    }

    /**
     * Parse the first DKIM-Signature header for fallback verification (backward compat)
     */
    private function parseDkimSignature($message): ?array
    {
        $dkimHeaderObj = $message->getHeader('DKIM-Signature');
        if ($dkimHeaderObj === null) {
            return null;
        }
        return $this->parseSingleDkimSignatureValue($dkimHeaderObj->getRawValue());
    }

    /**
     * Parse ALL DKIM-Signature headers from the message
     *
     * @return array<int, array{raw: string, version: string|null, algorithm: string|null, domain: string|null, selector: string|null, headers_signed: array, body_hash: string|null, signature: string|null}>
     */
    private function parseDkimSignatures($message): array
    {
        $signatures = [];
        $allHeaders = $message->getAllHeaders();
        foreach ($allHeaders as $header) {
            if (strcasecmp($header->getName(), 'DKIM-Signature') === 0) {
                $parsed = $this->parseSingleDkimSignatureValue($header->getRawValue());
                if ($parsed !== null) {
                    $signatures[] = $parsed;
                }
            }
        }
        return $signatures;
    }

    /**
     * Parse a single DKIM-Signature header value
     *
     * @return array{
     *     raw: string,
     *     version: string|null,
     *     algorithm: string|null,
     *     domain: string|null,
     *     selector: string|null,
     *     headers_signed: array,
     *     body_hash: string|null,
     *     signature: string|null
     * }|null
     */
    private function parseSingleDkimSignatureValue(string $rawValue): ?array
    {
        // Normalize whitespace (DKIM headers are often folded)
        $dkimHeader = preg_replace('/\s+/', ' ', trim($rawValue));

        $result = [
            'raw' => $dkimHeader,
            'version' => null,
            'algorithm' => null,
            'domain' => null,
            'selector' => null,
            'headers_signed' => [],
            'body_hash' => null,
            'signature' => null,
        ];

        // Parse key=value pairs
        $parts = explode(';', $dkimHeader);
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            if (preg_match('/^(\w+)\s*=\s*(.+)$/s', $part, $matches)) {
                $key = strtolower($matches[1]);
                $value = trim($matches[2]);

                switch ($key) {
                    case 'v':
                        $result['version'] = $value;
                        break;
                    case 'a':
                        $result['algorithm'] = $value;
                        break;
                    case 'd':
                        $result['domain'] = $value;
                        break;
                    case 's':
                        $result['selector'] = $value;
                        break;
                    case 'h':
                        $result['headers_signed'] = array_map('trim', explode(':', $value));
                        break;
                    case 'bh':
                        $result['body_hash'] = $value;
                        break;
                    case 'b':
                        $result['signature'] = preg_replace('/\s+/', '', $value);
                        break;
                }
            }
        }

        // Only return if we have essential fields
        if ($result['domain'] === null || $result['selector'] === null) {
            return null;
        }

        return $result;
    }

    /**
     * Parse Authentication-Results header(s)
     *
     * Format (RFC 7601):
     * Authentication-Results: mx.google.com;
     *     spf=pass (details) smtp.mailfrom=sender@example.com;
     *     dkim=pass header.i=@example.com header.s=selector1;
     *     dmarc=pass header.from=example.com
     *
     * @return array{
     *     raw: string|null,
     *     spf: array{result: string, details: array}|null,
     *     dkim: array{result: string, selector: string|null, domain: string|null, details: array}|null,
     *     dmarc: array{result: string, details: array}|null
     * }
     */
    private function parseAuthenticationResults($message): array
    {
        $result = [
            'raw' => null,
            'spf' => null,
            'dkim' => null,
            'dkim_results' => [],
            'dmarc' => null,
        ];

        $authResultsValues = [];

        // Get Authentication-Results headers
        // Check both standard and ARC (used by Google/Gmail) headers
        $headerNames = ['Authentication-Results', 'ARC-Authentication-Results'];

        foreach ($headerNames as $headerName) {
            // Use getRawValue() because zbateson parses structured headers
            // and getValue() would strip parameters (e.g., only return "mx.google.com"
            // instead of the full Authentication-Results content)
            $allHeaders = $message->getAllHeaders();
            foreach ($allHeaders as $header) {
                if (strcasecmp($header->getName(), $headerName) === 0) {
                    $value = $header->getRawValue();
                    if ($value !== null && !in_array($value, $authResultsValues, true)) {
                        $authResultsValues[] = $value;
                    }
                }
            }
        }

        if (empty($authResultsValues)) {
            return $result;
        }

        // Combine all Authentication-Results headers
        $result['raw'] = implode("\n", $authResultsValues);

        // Parse each Authentication-Results header
        foreach ($authResultsValues as $headerValue) {
            $parsed = $this->parseAuthenticationResultsValue($headerValue);

            // Merge results (prefer pass over other results)
            if ($parsed['spf'] !== null) {
                if ($result['spf'] === null || $parsed['spf']['result'] === 'pass') {
                    $result['spf'] = $parsed['spf'];
                }
            }
            if ($parsed['dkim'] !== null) {
                if ($result['dkim'] === null || $parsed['dkim']['result'] === 'pass') {
                    $result['dkim'] = $parsed['dkim'];
                }
            }
            // Merge all individual DKIM results
            if (!empty($parsed['dkim_results'])) {
                $result['dkim_results'] = array_merge($result['dkim_results'], $parsed['dkim_results']);
            }
            if ($parsed['dmarc'] !== null) {
                if ($result['dmarc'] === null || $parsed['dmarc']['result'] === 'pass') {
                    $result['dmarc'] = $parsed['dmarc'];
                }
            }
        }

        return $result;
    }

    /**
     * Parse a single Authentication-Results header value
     *
     * @param string $headerValue The header value (e.g., "mx.google.com; spf=pass ...")
     * @return array Parsed results
     */
    private function parseAuthenticationResultsValue(string $headerValue): array
    {
        $result = [
            'spf' => null,
            'dkim' => null,
            'dkim_results' => [],
            'dmarc' => null,
        ];

        // Normalize whitespace
        $headerValue = preg_replace('/\s+/', ' ', trim($headerValue));

        // Split by semicolon to get individual method results
        $parts = explode(';', $headerValue);

        $dkimResults = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Parse SPF result
            if (preg_match('/^spf\s*=\s*(\w+)/i', $part, $matches)) {
                $spfResult = strtolower($matches[1]);
                $details = $this->extractDetails($part);
                $result['spf'] = [
                    'result' => $spfResult,
                    'details' => $details,
                ];
                continue;
            }

            // Parse DKIM result — collect ALL entries
            if (preg_match('/^dkim\s*=\s*(\w+)/i', $part, $matches)) {
                $dkimResult = strtolower($matches[1]);
                $details = $this->extractDetails($part);

                // Extract selector and domain from header.s and header.d/header.i
                $selector = null;
                $domain = null;
                if (preg_match('/header\.s\s*=\s*([^\s;]+)/i', $part, $sMatch)) {
                    $selector = $sMatch[1];
                }
                if (preg_match('/header\.(?:d|i)\s*=\s*@?([^\s;]+)/i', $part, $dMatch)) {
                    $domain = ltrim($dMatch[1], '@');
                }

                $dkimResults[] = [
                    'result' => $dkimResult,
                    'selector' => $selector,
                    'domain' => $domain,
                    'details' => $details,
                ];
                continue;
            }

            // Parse DMARC result
            if (preg_match('/^dmarc\s*=\s*(\w+)/i', $part, $matches)) {
                $dmarcResult = strtolower($matches[1]);
                $details = $this->extractDetails($part);
                $result['dmarc'] = [
                    'result' => $dmarcResult,
                    'details' => $details,
                ];
                continue;
            }
        }

        // Store all DKIM results and pick the best one for backward compat
        $result['dkim_results'] = $dkimResults;
        if (!empty($dkimResults)) {
            // Prefer 'pass' result, otherwise take the first
            $result['dkim'] = $dkimResults[0];
            foreach ($dkimResults as $dr) {
                if ($dr['result'] === 'pass') {
                    $result['dkim'] = $dr;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Extract additional details from a method result string
     *
     * @param string $part The method result string (e.g., "spf=pass (google.com: ...)")
     * @return array Key-value pairs of extracted details
     */
    private function extractDetails(string $part): array
    {
        $details = [];

        // Extract comment in parentheses
        if (preg_match('/\(([^)]+)\)/', $part, $matches)) {
            $details['comment'] = $matches[1];
        }

        // Extract key=value pairs
        if (preg_match_all('/(\w+(?:\.\w+)?)\s*=\s*([^\s;()]+)/i', $part, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower($match[1]);
                // Skip the main result (spf=pass, dkim=pass, etc.)
                if (!in_array($key, ['spf', 'dkim', 'dmarc'], true)) {
                    $details[$key] = $match[2];
                }
            }
        }

        return $details;
    }
}
