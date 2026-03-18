<?php

declare(strict_types=1);

namespace Hn\MailSender\Import\Provider;

use Hn\MailSender\Import\SenderAddressSourceProviderInterface;
use Hn\MailSender\Import\ValueObject\SenderAddress;
use TYPO3\CMS\Core\Information\Typo3Version;
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

        $majorVersion = (new Typo3Version())->getMajorVersion();

        if ($majorVersion >= 13) {
            $formDefinitions = $this->loadAllFormDefinitionsV13($formPersistenceManager);
        } else {
            $formDefinitions = $this->loadAllFormDefinitionsV12($formPersistenceManager);
        }

        $addresses = [];
        $seen = [];

        foreach ($formDefinitions as $formDefinition) {
            foreach ($this->extractSenderAddresses($formDefinition) as $address) {
                if (!isset($seen[$address->email])) {
                    $addresses[] = $address;
                    $seen[$address->email] = true;
                }
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

    /**
     * Load all form definitions using the TYPO3 v12 API.
     *
     * In v12, listForms() and load() don't require settings parameters.
     *
     * @return array[]
     */
    private function loadAllFormDefinitionsV12(FormPersistenceManagerInterface $formPersistenceManager): array
    {
        try {
            $forms = $formPersistenceManager->listForms();
        } catch (\Exception) {
            return [];
        }

        $definitions = [];
        foreach (array_column($forms, 'persistenceIdentifier') as $identifier) {
            try {
                $definitions[] = $formPersistenceManager->load($identifier);
            } catch (\Exception) {
                // Skip forms that cannot be loaded
            }
        }
        return $definitions;
    }

    /**
     * Load all form definitions using the TYPO3 v13+ API.
     *
     * In v13, listForms() requires YAML formSettings and load() requires
     * both formSettings and typoScriptSettings.
     *
     * @return array[]
     */
    private function loadAllFormDefinitionsV13(FormPersistenceManagerInterface $formPersistenceManager): array
    {
        $configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
        $extFormConfigurationManager = GeneralUtility::makeInstance(ExtFormConfigurationManagerInterface::class);

        $typoScriptSettings = $configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'form'
        ) ?? [];

        $formSettings = $extFormConfigurationManager->getYamlConfiguration($typoScriptSettings, false);

        try {
            $forms = $formPersistenceManager->listForms($formSettings);
        } catch (\Exception) {
            return [];
        }

        $definitions = [];
        foreach (array_column($forms, 'persistenceIdentifier') as $identifier) {
            try {
                $definitions[] = $formPersistenceManager->load($identifier, $formSettings, $typoScriptSettings);
            } catch (\Exception) {
                // Skip forms that cannot be loaded
            }
        }
        return $definitions;
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
