<?php

declare(strict_types=1);

namespace Hn\MailSender\Import\Provider;

use Hn\MailSender\Import\SenderAddressSourceProviderInterface;
use Hn\MailSender\Import\ValueObject\SenderAddress;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
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
        $cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);

        $cached = $cache->get($cacheIdentifier);
        if (is_array($cached)) {
            return $this->mapToSenderAddresses($cached);
        }

        $senders = $this->fetchActiveSenders($apiKey);

        // Cache even an empty result so a misconfigured key or transient outage
        // does not trigger a fresh API call on every backend page view.
        $cache->set($cacheIdentifier, $senders, [], self::CACHE_LIFETIME);

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
     * Use a shorter timeout in the interactive backend module, where a user waits
     * for the page to render, and a longer one when sender detection runs from the
     * scheduler.
     */
    private function getRequestTimeout(): int
    {
        return $this->isSchedulerContext() ? self::TIMEOUT_BACKGROUND : self::TIMEOUT_INTERACTIVE;
    }

    /**
     * Whether sender detection is currently triggered by the scheduler, either via
     * cron (CLI) or run manually from the scheduler backend module. Running a task
     * from the browser must not shorten the timeout.
     */
    private function isSchedulerContext(): bool
    {
        // Cron: `typo3 scheduler:run`.
        if (PHP_SAPI === 'cli') {
            return true;
        }

        // Manual execution via the scheduler backend module.
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $module = $request instanceof ServerRequestInterface ? $request->getAttribute('module') : null;

        return $module instanceof ModuleInterface && $module->getIdentifier() === 'scheduler';
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
