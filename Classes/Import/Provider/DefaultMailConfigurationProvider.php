<?php

declare(strict_types=1);

namespace Hn\MailSender\Import\Provider;

use Hn\MailSender\Configuration\MailConfigurationProvider;
use Hn\MailSender\Import\SenderAddressSourceProviderInterface;
use Hn\MailSender\Import\ValueObject\SenderAddress;

/**
 * Provider that imports the default sender address from TYPO3 mail configuration
 *
 * Reads from $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress']
 */
class DefaultMailConfigurationProvider implements SenderAddressSourceProviderInterface
{
    public function __construct(
        private readonly MailConfigurationProvider $mailConfigurationProvider,
    ) {
    }

    public function getSenderAddresses(): array
    {
        $email = $this->mailConfigurationProvider->getDefaultMailFromAddress();
        if ($email === null) {
            return [];
        }

        $name = $this->mailConfigurationProvider->getDefaultMailFromName() ?? '';

        return [new SenderAddress($email, $name)];
    }

    public function getName(): string
    {
        return 'Default Mail Configuration';
    }
}
