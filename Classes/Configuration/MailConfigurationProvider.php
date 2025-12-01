<?php

declare(strict_types=1);

namespace Hn\MailSender\Configuration;

/**
 * Mail Configuration Provider
 *
 * Provides access to TYPO3 mail configuration settings,
 * particularly SMTP server configuration for SPF validation.
 */
class MailConfigurationProvider
{
    /**
     * Get SMTP server hostname (without port)
     */
    public function getSmtpServerHost(): ?string
    {
        $server = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] ?? '';
        if (empty($server)) {
            return null;
        }

        // Handle host:port format
        $parts = explode(':', $server);
        return $parts[0] ?: null;
    }

    /**
     * Get SMTP server IP addresses (resolves hostname)
     *
     * @return string[] Array of IP addresses
     */
    public function getSmtpServerIps(): array
    {
        $host = $this->getSmtpServerHost();
        if ($host === null) {
            return [];
        }

        // Check if already an IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // Resolve hostname to IPs (both IPv4 and IPv6)
        $ips = [];
        $ipv4Records = @dns_get_record($host, DNS_A);
        $ipv6Records = @dns_get_record($host, DNS_AAAA);

        foreach ($ipv4Records ?: [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }
        foreach ($ipv6Records ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * Check if SMTP transport is configured
     */
    public function isSmtpConfigured(): bool
    {
        $transport = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] ?? 'sendmail';
        return $transport === 'smtp';
    }

    /**
     * Get full mail configuration array
     *
     * @return array<string, mixed>
     */
    public function getMailConfiguration(): array
    {
        return $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
    }

    /**
     * Get default mail from address
     */
    public function getDefaultMailFromAddress(): ?string
    {
        $address = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] ?? '';
        return $address !== '' ? $address : null;
    }

    /**
     * Get default mail from name
     */
    public function getDefaultMailFromName(): ?string
    {
        $name = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'] ?? '';
        return $name !== '' ? $name : null;
    }
}
