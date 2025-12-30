<?php

declare(strict_types=1);

namespace Hn\MailSender\Import\Provider;

use Hn\MailSender\Import\SenderAddressSourceProviderInterface;
use Hn\MailSender\Import\ValueObject\SenderAddress;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Provider that imports sender addresses from form finisher configurations
 *
 * Scans all form definitions for EmailToSender and EmailToReceiver finishers
 * and extracts their configured sender addresses.
 *
 * This provider is only active when the TYPO3 Form extension is installed.
 */
class FormFinisherConfigurationProvider implements SenderAddressSourceProviderInterface
{
    public function getSenderAddresses(): array
    {
        // Skip if Form extension is not available
        if (!ExtensionManagementUtility::isLoaded('form')) {
            return [];
        }

        $formPersistenceManager = $this->getFormPersistenceManager();
        if ($formPersistenceManager === null) {
            return [];
        }

        $configurationManager = $this->getConfigurationManager();
        if ($configurationManager === null) {
            return [];
        }

        // Get required configuration for FormPersistenceManager methods
        $formSettings = $configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'form'
        ) ?? [];
        $typoScriptSettings = $configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        ) ?? [];

        $addresses = [];
        $seen = [];

        foreach ($this->listAllForms($formPersistenceManager, $formSettings) as $formPersistenceIdentifier) {
            try {
                $formDefinition = $formPersistenceManager->load(
                    $formPersistenceIdentifier,
                    $formSettings,
                    $typoScriptSettings
                );
                foreach ($this->extractSenderAddresses($formDefinition) as $address) {
                    // Deduplicate within this provider
                    if (!isset($seen[$address->email])) {
                        $addresses[] = $address;
                        $seen[$address->email] = true;
                    }
                }
            } catch (\Exception) {
                // Skip forms that cannot be loaded
                continue;
            }
        }

        return $addresses;
    }

    public function getName(): string
    {
        return 'Form Finisher Configuration';
    }

    /**
     * Get the FormPersistenceManager if available
     *
     * Uses late binding to avoid compile-time dependency on the Form extension.
     */
    private function getFormPersistenceManager(): ?object
    {
        try {
            // Use string class name to avoid compile-time dependency
            $className = 'TYPO3\\CMS\\Form\\Mvc\\Persistence\\FormPersistenceManagerInterface';
            if (!interface_exists($className)) {
                return null;
            }
            return GeneralUtility::makeInstance($className);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get the ConfigurationManager
     */
    private function getConfigurationManager(): ?ConfigurationManagerInterface
    {
        try {
            return GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * List all form persistence identifiers
     *
     * @return string[]
     */
    private function listAllForms(object $formPersistenceManager, array $formSettings): array
    {
        try {
            $forms = $formPersistenceManager->listForms($formSettings);
            return array_column($forms, 'persistenceIdentifier');
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Extract sender addresses from form finishers
     *
     * @return SenderAddress[]
     */
    private function extractSenderAddresses(array $formDefinition): array
    {
        $addresses = [];
        $finishers = $formDefinition['finishers'] ?? [];

        foreach ($finishers as $finisher) {
            $identifier = $finisher['identifier'] ?? '';

            // Only process email finishers
            if (!in_array($identifier, ['EmailToSender', 'EmailToReceiver'], true)) {
                continue;
            }

            $options = $finisher['options'] ?? [];

            // Extract sender address
            $senderAddress = $options['senderAddress'] ?? null;
            if ($senderAddress !== null && is_string($senderAddress) && $senderAddress !== '') {
                // Skip form element references like {email}
                if (!str_starts_with($senderAddress, '{')) {
                    $senderName = $options['senderName'] ?? '';
                    if (is_string($senderName) && !str_starts_with($senderName, '{')) {
                        $addresses[] = new SenderAddress($senderAddress, $senderName);
                    } else {
                        $addresses[] = new SenderAddress($senderAddress);
                    }
                }
            }
        }

        return $addresses;
    }
}
