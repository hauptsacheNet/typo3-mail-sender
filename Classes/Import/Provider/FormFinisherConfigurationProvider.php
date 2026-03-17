<?php

declare(strict_types=1);

namespace Hn\MailSender\Import\Provider;

use Hn\MailSender\Import\SenderAddressSourceProviderInterface;
use Hn\MailSender\Import\ValueObject\SenderAddress;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as ExtFormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

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
        // Skip if the Form extension is not available
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

        $extFormConfigurationManager = $this->getExtFormConfigurationManager();
        if ($extFormConfigurationManager === null) {
            return [];
        }

        // Get TypoScript settings for the form extension
        $typoScriptSettings = $configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'form'
        ) ?? [];

        // Merge YAML configuration (allowedFileMounts etc.) with TypoScript overrides
        $formSettings = $extFormConfigurationManager->getYamlConfiguration($typoScriptSettings, false);

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

    private function getFormPersistenceManager(): ?FormPersistenceManagerInterface
    {
        try {
            return GeneralUtility::makeInstance(FormPersistenceManagerInterface::class);
        } catch (\Exception) {
            return null;
        }
    }

    private function getConfigurationManager(): ?ConfigurationManagerInterface
    {
        try {
            return GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
        } catch (\Exception) {
            return null;
        }
    }

    private function getExtFormConfigurationManager(): ?ExtFormConfigurationManagerInterface
    {
        try {
            return GeneralUtility::makeInstance(ExtFormConfigurationManagerInterface::class);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * List all form persistence identifiers
     *
     * @return string[]
     */
    private function listAllForms(FormPersistenceManagerInterface $formPersistenceManager, array $formSettings): array
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

            // Skip form element references like {email}
            if (is_string($senderAddress) && $senderAddress !== '' && !str_starts_with($senderAddress, '{')) {
                $senderName = $options['senderName'] ?? '';
                if (is_string($senderName) && !str_starts_with($senderName, '{')) {
                    $addresses[] = new SenderAddress($senderAddress, $senderName);
                } else {
                    $addresses[] = new SenderAddress($senderAddress);
                }
            }
        }

        return $addresses;
    }
}
