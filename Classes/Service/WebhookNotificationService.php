<?php

declare(strict_types=1);

namespace Hn\MailSender\Service;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Service for sending webhook notifications when validation fails
 */
class WebhookNotificationService
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_SLACK = 'slack';
    public const FORMAT_TEAMS = 'teams';

    private const REGISTRY_NAMESPACE = 'tx_mailsender';
    private const KEY_WEBHOOK_URL = 'webhook_url';
    private const KEY_WEBHOOK_FORMAT = 'webhook_format';
    private const KEY_LAST_RESULT = 'webhook_last_result';
    private const KEY_LAST_NOTIFIED_STATUS = 'webhook_last_notified_status';

    public function __construct(
        private readonly Registry $registry,
        private readonly RequestFactory $requestFactory,
        private readonly SiteFinder $siteFinder,
    ) {
    }

    /**
     * Get site information (name and backend URL)
     *
     * @return array{siteName: string, backendUrl: string|null}
     */
    private function getSiteInfo(): array
    {
        $siteName = $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'TYPO3';
        $backendUrl = null;

        try {
            $sites = $this->siteFinder->getAllSites();
            if (!empty($sites)) {
                // Get the first site (sorted by root page ID)
                $site = reset($sites);
                $baseUrl = rtrim((string)$site->getBase(), '/');
                $backendUrl = $baseUrl . '/typo3';
            }
        } catch (\Exception $e) {
            // SiteFinder might throw if no sites are configured
        }

        return [
            'siteName' => $siteName,
            'backendUrl' => $backendUrl,
        ];
    }

    /**
     * Get the configured webhook URL
     */
    public function getWebhookUrl(): string
    {
        return (string)$this->registry->get(self::REGISTRY_NAMESPACE, self::KEY_WEBHOOK_URL, '');
    }

    /**
     * Set the webhook URL
     */
    public function setWebhookUrl(string $url): void
    {
        $this->registry->set(self::REGISTRY_NAMESPACE, self::KEY_WEBHOOK_URL, $url);
    }

    /**
     * Get the configured webhook format
     */
    public function getWebhookFormat(): string
    {
        return (string)$this->registry->get(self::REGISTRY_NAMESPACE, self::KEY_WEBHOOK_FORMAT, self::FORMAT_JSON);
    }

    /**
     * Set the webhook format
     */
    public function setWebhookFormat(string $format): void
    {
        if (!in_array($format, [self::FORMAT_JSON, self::FORMAT_SLACK, self::FORMAT_TEAMS], true)) {
            $format = self::FORMAT_JSON;
        }
        $this->registry->set(self::REGISTRY_NAMESPACE, self::KEY_WEBHOOK_FORMAT, $format);
    }

    /**
     * Get the last webhook result
     */
    public function getLastResult(): ?array
    {
        return $this->registry->get(self::REGISTRY_NAMESPACE, self::KEY_LAST_RESULT);
    }

    /**
     * Check if webhook is configured and URL is valid
     */
    public function isConfigured(): bool
    {
        $url = $this->getWebhookUrl();
        if (empty($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Only allow http/https schemes
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme ?? ''), ['http', 'https'], true)) {
            return false;
        }

        // Prevent SSRF by blocking internal/private addresses
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }

        // Block localhost variations
        $lowerHost = strtolower($host);
        if (in_array($lowerHost, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return false;
        }

        // Resolve hostname and check if it's a private IP
        $ip = gethostbyname($host);
        if ($ip !== $host && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return true;
    }

    /**
     * Send a test webhook message
     *
     * @return array{success: bool, message: string}
     */
    public function sendTestMessage(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Webhook URL is not configured or invalid',
            ];
        }

        $testData = [
            'type' => 'test',
            'message' => 'This is a test message from the TYPO3 Mail Sender extension',
            'timestamp' => time(),
            'status' => 'test',
        ];

        return $this->send($testData);
    }

    /**
     * Notify about validation results
     *
     * Sends a notification when:
     * - Status changes from OK to having issues (something broke)
     * - Status changes from having issues to OK (recovery)
     * - The specific issues changed (different addresses failing)
     *
     * Does NOT send repeated notifications for the same issues.
     * Only stores the state hash if webhook delivery succeeds.
     *
     * @param array $validationStats Statistics array with keys: total, valid, warning, invalid, pending
     * @param array $failedAddresses Array of failed address info [{email, status, errors}]
     * @return array{success: bool, message: string}|null Returns null if notification was skipped
     */
    public function notifyValidationResults(array $validationStats, array $failedAddresses): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $hasIssues = $validationStats['invalid'] > 0 || $validationStats['warning'] > 0;
        $currentStatusHash = $this->calculateStatusHash($validationStats, $failedAddresses);
        $lastStatusHash = $this->registry->get(self::REGISTRY_NAMESPACE, self::KEY_LAST_NOTIFIED_STATUS);

        // Check if status changed
        if ($currentStatusHash === $lastStatusHash) {
            // Status hasn't changed, skip notification
            return null;
        }

        // Determine if this is a recovery (was broken, now all good)
        $wasOk = $lastStatusHash === null || $lastStatusHash === $this->calculateStatusHash(['invalid' => 0, 'warning' => 0], []);
        $isRecovery = !$hasIssues && !$wasOk;

        if ($isRecovery) {
            // Send recovery notification
            $data = [
                'type' => 'validation_recovered',
                'timestamp' => time(),
                'statistics' => $validationStats,
                'summary' => 'All ' . ($validationStats['total'] ?? 0) . ' email sender addresses are now valid.',
            ];
        } elseif ($hasIssues) {
            // Send alert notification
            $data = [
                'type' => 'validation_alert',
                'timestamp' => time(),
                'statistics' => $validationStats,
                'failures' => $failedAddresses,
                'summary' => $this->buildSummaryMessage($validationStats),
            ];
        } else {
            // No issues and not a recovery (first run with all OK) - skip
            // But still store the hash so we can detect future changes
            $this->registry->set(self::REGISTRY_NAMESPACE, self::KEY_LAST_NOTIFIED_STATUS, $currentStatusHash);
            return null;
        }

        $result = $this->send($data);

        // Only store the new status hash if webhook delivery succeeded
        if ($result['success']) {
            $this->registry->set(self::REGISTRY_NAMESPACE, self::KEY_LAST_NOTIFIED_STATUS, $currentStatusHash);
        }

        return $result;
    }

    /**
     * Send data to the webhook
     *
     * @return array{success: bool, message: string}
     */
    private function send(array $data): array
    {
        $url = $this->getWebhookUrl();
        $format = $this->getWebhookFormat();

        try {
            $payload = $this->formatPayload($data, $format);

            $response = $this->requestFactory->request(
                $url,
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'timeout' => 10,
                ]
            );

            $statusCode = $response->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;

            $result = [
                'success' => $success,
                'message' => $success
                    ? 'Webhook sent successfully (HTTP ' . $statusCode . ')'
                    : 'Webhook failed with HTTP ' . $statusCode,
                'timestamp' => time(),
                'statusCode' => $statusCode,
            ];

            $this->registry->set(self::REGISTRY_NAMESPACE, self::KEY_LAST_RESULT, $result);

            return $result;

        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Webhook error: ' . $e->getMessage(),
                'timestamp' => time(),
            ];

            $this->registry->set(self::REGISTRY_NAMESPACE, self::KEY_LAST_RESULT, $result);

            return $result;
        }
    }

    /**
     * Format payload based on the configured format
     */
    private function formatPayload(array $data, string $format): array
    {
        return match ($format) {
            self::FORMAT_SLACK => $this->formatSlackPayload($data),
            self::FORMAT_TEAMS => $this->formatTeamsPayload($data),
            default => $this->formatJsonPayload($data),
        };
    }

    /**
     * Format as generic JSON payload
     */
    private function formatJsonPayload(array $data): array
    {
        $siteInfo = $this->getSiteInfo();

        return [
            'source' => 'typo3-mail-sender',
            'type' => $data['type'],
            'timestamp' => date('c', $data['timestamp']),
            'site' => [
                'name' => $siteInfo['siteName'],
                'backendUrl' => $siteInfo['backendUrl'],
            ],
            'data' => $data,
        ];
    }

    /**
     * Format as Slack-compatible payload
     */
    private function formatSlackPayload(array $data): array
    {
        $type = $data['type'] ?? '';
        $siteInfo = $this->getSiteInfo();
        $siteName = $siteInfo['siteName'];
        $backendUrl = $siteInfo['backendUrl'];

        if ($type === 'test') {
            $headerText = $siteName . ': Mail Sender Test';
            $blocks = [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $headerText,
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $data['message'] ?? 'Test message from TYPO3 Mail Sender extension',
                    ],
                ],
            ];

            if ($backendUrl) {
                $blocks[] = [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => '<' . $backendUrl . '|Open TYPO3 Backend>',
                        ],
                    ],
                ];
            }

            return [
                'text' => $headerText,
                'blocks' => $blocks,
            ];
        }

        if ($type === 'validation_recovered') {
            $headerText = $siteName . ': Mail Sender - All Clear';
            $summary = $data['summary'] ?? 'All email sender addresses are now valid.';
            $blocks = [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $headerText,
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => ':white_check_mark: ' . $summary,
                    ],
                ],
            ];

            if ($backendUrl) {
                $blocks[] = [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => '<' . $backendUrl . '|Open TYPO3 Backend>',
                        ],
                    ],
                ];
            }

            return [
                'text' => $headerText . ': ' . $summary,
                'blocks' => $blocks,
            ];
        }

        // validation_alert
        $summary = $data['summary'] ?? 'Validation issues detected';
        $stats = $data['statistics'] ?? [];
        $failures = $data['failures'] ?? [];
        $headerText = $siteName . ': Mail Sender Validation Alert';

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => $headerText,
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $summary,
                ],
            ],
        ];

        // Add statistics
        if (!empty($stats)) {
            $statLines = [];
            if ($stats['invalid'] ?? 0) {
                $statLines[] = ':x: *' . $stats['invalid'] . '* invalid';
            }
            if ($stats['warning'] ?? 0) {
                $statLines[] = ':warning: *' . $stats['warning'] . '* with warnings';
            }
            if ($stats['valid'] ?? 0) {
                $statLines[] = ':white_check_mark: *' . $stats['valid'] . '* valid';
            }

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => implode('  |  ', $statLines),
                ],
            ];
        }

        // Add failed addresses (limited to first 5)
        if (!empty($failures)) {
            $failureText = "*Affected addresses:*\n";
            foreach (array_slice($failures, 0, 5) as $failure) {
                $failureText .= '• `' . ($failure['email'] ?? 'unknown') . '` - ' . ($failure['status'] ?? 'unknown') . "\n";
            }
            if (count($failures) > 5) {
                $failureText .= '_... and ' . (count($failures) - 5) . ' more_';
            }

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $failureText,
                ],
            ];
        }

        // Add backend link
        if ($backendUrl) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => '<' . $backendUrl . '|Open TYPO3 Backend>',
                    ],
                ],
            ];
        }

        return [
            'text' => $headerText . ': ' . $summary,
            'blocks' => $blocks,
        ];
    }

    /**
     * Format as Microsoft Teams Adaptive Card payload
     */
    private function formatTeamsPayload(array $data): array
    {
        $type = $data['type'] ?? '';
        $siteInfo = $this->getSiteInfo();
        $siteName = $siteInfo['siteName'];
        $backendUrl = $siteInfo['backendUrl'];

        if ($type === 'test') {
            $body = [
                [
                    'type' => 'TextBlock',
                    'size' => 'Large',
                    'weight' => 'Bolder',
                    'text' => $siteName . ': Mail Sender Test',
                ],
                [
                    'type' => 'TextBlock',
                    'text' => $data['message'] ?? 'Test message from TYPO3 Mail Sender extension',
                    'wrap' => true,
                ],
            ];

            $actions = [];
            if ($backendUrl) {
                $actions[] = [
                    'type' => 'Action.OpenUrl',
                    'title' => 'Open TYPO3 Backend',
                    'url' => $backendUrl,
                ];
            }

            return [
                'type' => 'message',
                'attachments' => [
                    [
                        'contentType' => 'application/vnd.microsoft.card.adaptive',
                        'content' => [
                            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                            'type' => 'AdaptiveCard',
                            'version' => '1.4',
                            'body' => $body,
                            'actions' => $actions,
                        ],
                    ],
                ],
            ];
        }

        if ($type === 'validation_recovered') {
            $summary = $data['summary'] ?? 'All email sender addresses are now valid.';
            $body = [
                [
                    'type' => 'TextBlock',
                    'size' => 'Large',
                    'weight' => 'Bolder',
                    'text' => $siteName . ': Mail Sender - All Clear',
                    'color' => 'Good',
                ],
                [
                    'type' => 'TextBlock',
                    'text' => $summary,
                    'wrap' => true,
                ],
            ];

            $actions = [];
            if ($backendUrl) {
                $actions[] = [
                    'type' => 'Action.OpenUrl',
                    'title' => 'Open TYPO3 Backend',
                    'url' => $backendUrl,
                ];
            }

            return [
                'type' => 'message',
                'attachments' => [
                    [
                        'contentType' => 'application/vnd.microsoft.card.adaptive',
                        'content' => [
                            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                            'type' => 'AdaptiveCard',
                            'version' => '1.4',
                            'body' => $body,
                            'actions' => $actions,
                        ],
                    ],
                ],
            ];
        }

        // validation_alert
        $summary = $data['summary'] ?? 'Validation issues detected';
        $stats = $data['statistics'] ?? [];
        $failures = $data['failures'] ?? [];

        $body = [
            [
                'type' => 'TextBlock',
                'size' => 'Large',
                'weight' => 'Bolder',
                'text' => $siteName . ': Mail Sender Validation Alert',
                'color' => 'Attention',
            ],
            [
                'type' => 'TextBlock',
                'text' => $summary,
                'wrap' => true,
            ],
        ];

        // Add statistics as facts
        if (!empty($stats)) {
            $facts = [];
            if ($stats['invalid'] ?? 0) {
                $facts[] = ['title' => 'Invalid', 'value' => (string)$stats['invalid']];
            }
            if ($stats['warning'] ?? 0) {
                $facts[] = ['title' => 'Warnings', 'value' => (string)$stats['warning']];
            }
            if ($stats['valid'] ?? 0) {
                $facts[] = ['title' => 'Valid', 'value' => (string)$stats['valid']];
            }

            $body[] = [
                'type' => 'FactSet',
                'facts' => $facts,
            ];
        }

        // Add failed addresses
        if (!empty($failures)) {
            $body[] = [
                'type' => 'TextBlock',
                'text' => 'Affected addresses:',
                'weight' => 'Bolder',
            ];

            foreach (array_slice($failures, 0, 5) as $failure) {
                $body[] = [
                    'type' => 'TextBlock',
                    'text' => '• ' . ($failure['email'] ?? 'unknown') . ' - ' . ($failure['status'] ?? 'unknown'),
                    'spacing' => 'None',
                ];
            }

            if (count($failures) > 5) {
                $body[] = [
                    'type' => 'TextBlock',
                    'text' => '... and ' . (count($failures) - 5) . ' more',
                    'isSubtle' => true,
                    'spacing' => 'None',
                ];
            }
        }

        $actions = [];
        if ($backendUrl) {
            $actions[] = [
                'type' => 'Action.OpenUrl',
                'title' => 'Open TYPO3 Backend',
                'url' => $backendUrl,
            ];
        }

        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => $body,
                        'actions' => $actions,
                    ],
                ],
            ],
        ];
    }

    /**
     * Build a human-readable summary message
     */
    private function buildSummaryMessage(array $stats): string
    {
        $parts = [];

        if ($stats['invalid'] ?? 0) {
            $parts[] = $stats['invalid'] . ' invalid';
        }
        if ($stats['warning'] ?? 0) {
            $parts[] = $stats['warning'] . ' with warnings';
        }

        $total = $stats['total'] ?? 0;
        return 'Email sender validation: ' . implode(', ', $parts) . ' out of ' . $total . ' addresses';
    }

    /**
     * Calculate a hash of the current status for change detection
     */
    private function calculateStatusHash(array $stats, array $failures): string
    {
        $statusData = [
            'invalid' => $stats['invalid'] ?? 0,
            'warning' => $stats['warning'] ?? 0,
            'failures' => array_map(
                fn($f) => ($f['email'] ?? 'unknown') . ':' . ($f['status'] ?? 'unknown'),
                $failures
            ),
        ];
        return md5(json_encode($statusData, JSON_THROW_ON_ERROR));
    }
}
