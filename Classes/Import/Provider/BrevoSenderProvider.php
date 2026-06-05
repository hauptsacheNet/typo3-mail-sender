<?php

declare(strict_types=1);

namespace Hn\MailSender\Import\Provider;

use Hn\MailSender\Import\SenderAddressSourceProviderInterface;
use Hn\MailSender\Import\ValueObject\SenderAddress;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Provider that imports verified senders from a Brevo account.
 *
 * Active only when a Brevo API key is configured in the extension configuration
 * ($brevoApiKey). When configured, it fetches the senders from the Brevo API
 * (https://api.brevo.com/v3/senders) and imports the active ones.
 *
 * The API response is cached for a short period (see CACHE_LIFETIME). Because
 * sender detection runs on every backend module view, this doubles as a rate
 * limit so we never hammer the Brevo API. The cache lives in the system cache
 * group, so a regular "flush system caches" forces an immediate refresh.
 *
 * Note: this is an additive import. Senders that are later deactivated or removed
 * in Brevo are not automatically disabled or deleted locally.
 */
class BrevoSenderProvider implements SenderAddressSourceProviderInterface
{
    private const API_ENDPOINT = 'https://api.brevo.com/v3/senders';

    /**
     * Cache frontend identifier (registered in ext_localconf.php).
     */
    private const CACHE_IDENTIFIER = 'mailsender';

    /**
     * Lifetime of a cached sender list in seconds. Also acts as the minimum
     * interval between two Brevo API calls.
     */
    private const CACHE_LIFETIME = 60;

    /**
     * Request timeout in seconds when running interactively (e.g. the backend module),
     * where a fast response matters more than completeness.
     */
    private const TIMEOUT_INTERACTIVE = 5;

    /**
     * Request timeout in seconds in non-interactive contexts (scheduler/CLI cron),
     * where we can afford to wait longer for a slow API.
     */
    private const TIMEOUT_BACKGROUND = 15;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly CacheManager $cacheManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getSenderAddresses(): array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            // Not configured: this provider is a no-op.
            return [];
        }

        // Include the key in the cache identifier so switching keys (e.g. a
        // different Brevo account) never serves senders from the previous one.
        $cacheIdentifier = 'brevo_senders_' . sha1($apiKey);

        $cached = $this->readCache($cacheIdentifier);
        if (is_array($cached)) {
            return $this->mapToSenderAddresses($cached);
        }

        $senders = $this->fetchActiveSenders($apiKey);

        // Cache even an empty result so a misconfigured key or transient outage
        // does not trigger a fresh API call on every backend page view.
        $this->writeCache($cacheIdentifier, $senders);

        return $this->mapToSenderAddresses($senders);
    }

    public function getName(): string
    {
        return 'Brevo';
    }

    private function getApiKey(): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get('mail_sender', 'brevoApiKey'));
        } catch (\Throwable) {
            // Extension not configured yet / path missing.
            return '';
        }
    }

    /**
     * @return array<int, array{email: string, name: string}>|null Cached senders, or null on miss/error
     */
    private function readCache(string $identifier): ?array
    {
        try {
            $value = $this->cacheManager->getCache(self::CACHE_IDENTIFIER)->get($identifier);
            return is_array($value) ? $value : null;
        } catch (\Throwable) {
            // Cache unavailable (not registered, backend error, ...): treat as a miss.
            return null;
        }
    }

    /**
     * @param array<int, array{email: string, name: string}> $senders
     */
    private function writeCache(string $identifier, array $senders): void
    {
        try {
            $this->cacheManager->getCache(self::CACHE_IDENTIFIER)->set($identifier, $senders, [], self::CACHE_LIFETIME);
        } catch (\Throwable) {
            // Caching is best-effort: never let a cache failure break sender import.
        }
    }

    /**
     * Fetch active senders from the Brevo API.
     *
     * Always degrades gracefully: any error results in an empty list so a Brevo
     * outage never blocks the rest of the sender import.
     *
     * @return array<int, array{email: string, name: string}>
     */
    private function fetchActiveSenders(string $apiKey): array
    {
        try {
            $response = $this->requestFactory->request(self::API_ENDPOINT, 'GET', [
                'headers' => [
                    'api-key' => $apiKey,
                    'Accept' => 'application/json',
                ],
                'timeout' => $this->getRequestTimeout(),
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger?->warning(
                    'Brevo sender import returned HTTP {status}',
                    ['status' => $response->getStatusCode()]
                );
                return [];
            }

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'Brevo sender import failed: {message}',
                ['message' => $e->getMessage()]
            );
            return [];
        }

        $senders = [];
        foreach ($data['senders'] ?? [] as $sender) {
            if (!is_array($sender) || ($sender['active'] ?? false) !== true) {
                continue;
            }

            $email = trim((string)($sender['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $senders[] = [
                'email' => $email,
                'name' => trim((string)($sender['name'] ?? '')),
            ];
        }

        return $senders;
    }

    /**
     * Use a shorter timeout in interactive contexts (e.g. the backend module) and a
     * longer one in non-interactive ones (scheduler/CLI cron), where completeness
     * matters more than latency.
     *
     * Environment::isCli() is the idiomatic check and is always initialized at TYPO3
     * runtime; it is guarded so a non-initialized environment can never break the import.
     */
    private function getRequestTimeout(): int
    {
        try {
            $isCli = Environment::isCli();
        } catch (\Throwable) {
            $isCli = PHP_SAPI === 'cli';
        }

        return $isCli ? self::TIMEOUT_BACKGROUND : self::TIMEOUT_INTERACTIVE;
    }

    /**
     * @param array<int, array{email: string, name: string}> $senders
     * @return SenderAddress[]
     */
    private function mapToSenderAddresses(array $senders): array
    {
        return array_map(
            static fn(array $sender): SenderAddress => new SenderAddress($sender['email'], $sender['name']),
            $senders
        );
    }
}
