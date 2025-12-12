<?php

declare(strict_types=1);

namespace Hn\MailSender\Validation\Validator;

use Hn\MailSender\Validation\SenderAddressValidatorInterface;
use Hn\MailSender\Validation\ValueObject\ValidationResult;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Email Existence Validator
 *
 * Validates email existence by connecting to the mail server via SMTP.
 * This validator uses RCPT TO command to verify if the email address exists.
 *
 * Note: Many mail servers have disabled VRFY and may not respond accurately
 * to RCPT TO checks, so results should be treated as hints rather than definitive.
 */
class EmailExistenceValidator implements SenderAddressValidatorInterface
{
    private const TIMEOUT_SECONDS = 10;
    private const SMTP_PORT = 25;
    private const CACHE_LIFETIME = 86400; // 24 hours

    private FrontendInterface $cache;

    public function __construct(
        CacheManager $cacheManager,
    ) {
        $this->cache = $cacheManager->getCache('hash');
    }

    public function validate(string $email, string $domain, ?array $emlData = null): ValidationResult
    {
        // Check cache first
        $cacheKey = 'email_existence_' . hash('sha256', $email);
        $cachedResult = $this->cache->get($cacheKey);
        if ($cachedResult !== false) {
            return unserialize($cachedResult, ['allowed_classes' => [ValidationResult::class]]);
        }

        $details = [];
        $warnings = [];

        // Get MX records to find mail server
        $mxRecords = @dns_get_record($domain, DNS_MX);
        if ($mxRecords === false || empty($mxRecords)) {
            return ValidationResult::warning(
                'Cannot check email existence: No MX records found',
                ['reason' => 'no_mx_records']
            );
        }

        // Sort by priority (lowest first)
        usort($mxRecords, fn($a, $b) => ($a['pri'] ?? 999) <=> ($b['pri'] ?? 999));

        $details['mx_tested'] = $mxRecords[0]['target'] ?? 'unknown';

        // Try to connect to the first MX server
        $mxHost = $mxRecords[0]['target'] ?? null;
        if ($mxHost === null) {
            return ValidationResult::warning(
                'Cannot check email existence: Invalid MX record',
                ['reason' => 'invalid_mx_record']
            );
        }

        try {
            $smtpCheck = $this->checkSmtpServer($mxHost, $email);

            if ($smtpCheck['connected']) {
                $details['smtp_connected'] = true;
                $details['smtp_response'] = $smtpCheck['response'] ?? '';

                if ($smtpCheck['accepted']) {
                    return $this->cacheResult(
                        $cacheKey,
                        ValidationResult::valid(
                            'Email address accepted by mail server',
                            $details
                        )
                    );
                }

                // Check if our test sender was rejected (SPF/policy issue on our end)
                if ($smtpCheck['mail_from_rejected'] ?? false) {
                    return $this->cacheResult(
                        $cacheKey,
                        ValidationResult::warning(
                            'Cannot verify email: Test sender rejected by mail server',
                            ['reason' => 'mail_from_rejected', ...$details]
                        )
                    );
                }

                if ($smtpCheck['uncertain']) {
                    $warnings[] = 'Mail server did not provide definitive answer';
                    return $this->cacheResult(
                        $cacheKey,
                        ValidationResult::warning(
                            'Email existence uncertain: ' . ($smtpCheck['response'] ?? 'Server response unclear'),
                            ['warnings' => $warnings, ...$details]
                        )
                    );
                }

                return $this->cacheResult(
                    $cacheKey,
                    ValidationResult::invalid(
                        'Email address rejected by mail server: ' . ($smtpCheck['response'] ?? 'Unknown reason'),
                        ['smtp_rejected' => true, ...$details]
                    )
                );
            }

            return $this->cacheResult(
                $cacheKey,
                ValidationResult::warning(
                    'Cannot check email existence: Unable to connect to mail server',
                    ['reason' => 'smtp_connection_failed', 'details' => $smtpCheck['error'] ?? 'Unknown error']
                )
            );
        } catch (\Throwable $e) {
            return $this->cacheResult(
                $cacheKey,
                ValidationResult::warning(
                    'Cannot check email existence: ' . $e->getMessage(),
                    ['exception' => get_class($e), 'reason' => 'smtp_check_error']
                )
            );
        }
    }

    /**
     * Cache and return a validation result
     */
    private function cacheResult(string $cacheKey, ValidationResult $result): ValidationResult
    {
        $this->cache->set($cacheKey, serialize($result), ['email_existence'], self::CACHE_LIFETIME);
        return $result;
    }

    /**
     * Check SMTP server for email acceptance
     *
     * @return array{connected: bool, accepted: bool, uncertain: bool, mail_from_rejected?: bool, response?: string, error?: string}
     */
    private function checkSmtpServer(string $mxHost, string $email): array
    {
        $socket = @fsockopen($mxHost, self::SMTP_PORT, $errno, $errstr, self::TIMEOUT_SECONDS);

        if ($socket === false) {
            return [
                'connected' => false,
                'accepted' => false,
                'uncertain' => false,
                'error' => "Connection failed: $errstr ($errno)",
            ];
        }

        stream_set_timeout($socket, self::TIMEOUT_SECONDS);

        try {
            // Read initial banner
            $response = $this->readSmtpResponse($socket);
            if (!str_starts_with($response, '220')) {
                return [
                    'connected' => false,
                    'accepted' => false,
                    'uncertain' => false,
                    'response' => $response,
                ];
            }

            // Send EHLO
            fwrite($socket, "EHLO example.com\r\n");
            $response = $this->readSmtpResponse($socket);
            if (!str_starts_with($response, '250')) {
                // Try HELO instead
                fwrite($socket, "HELO example.com\r\n");
                $response = $this->readSmtpResponse($socket);
            }

            // Send MAIL FROM
            fwrite($socket, "MAIL FROM:<test@example.com>\r\n");
            $mailFromResponse = $this->readSmtpResponse($socket);

            // Check if MAIL FROM was rejected (e.g., SPF policy rejection)
            if (!str_starts_with($mailFromResponse, '250')) {
                return [
                    'connected' => true,
                    'accepted' => false,
                    'uncertain' => true,
                    'mail_from_rejected' => true,
                    'response' => $mailFromResponse,
                ];
            }

            // Send RCPT TO (actual test)
            fwrite($socket, "RCPT TO:<$email>\r\n");
            $response = $this->readSmtpResponse($socket);

            // Send QUIT
            fwrite($socket, "QUIT\r\n");
            $this->readSmtpResponse($socket);

            // Analyze RCPT TO response
            if (str_starts_with($response, '250') || str_starts_with($response, '251')) {
                return [
                    'connected' => true,
                    'accepted' => true,
                    'uncertain' => false,
                    'response' => $response,
                ];
            }

            if (str_starts_with($response, '550') || str_starts_with($response, '551') || str_starts_with($response, '553')) {
                return [
                    'connected' => true,
                    'accepted' => false,
                    'uncertain' => false,
                    'response' => $response,
                ];
            }

            // Uncertain responses (greylisting, temporary failures, etc.)
            return [
                'connected' => true,
                'accepted' => false,
                'uncertain' => true,
                'response' => $response,
            ];
        } finally {
            fclose($socket);
        }
    }

    /**
     * Read SMTP server response
     */
    private function readSmtpResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            // Stop when we get a response that doesn't have a dash after the code
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }

        return trim($response);
    }

    public function getName(): string
    {
        return 'Email Existence Validator';
    }

    public function getPriority(): int
    {
        return 20; // Run last, after syntax and DNS checks
    }
}
