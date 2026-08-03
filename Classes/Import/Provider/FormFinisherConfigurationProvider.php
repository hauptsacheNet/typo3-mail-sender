<?php

declare(strict_types=1);

namespace Hn\MailSender\Import\Provider;

use Hn\MailSender\Import\SenderAddressSourceProviderInterface;
use Hn\MailSender\Import\ValueObject\SenderAddress;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\DTO\SearchCriteria;
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
    /**
     * @param FormPersistenceManagerInterface|null $formPersistenceManager Injected when EXT:form is
     *        installed. Optional (see Configuration/Services.yaml) because this provider is also
     *        registered on systems without EXT:form. It is resolved via DI rather than makeInstance()
     *        because the interface is no longer publicly resolvable through makeInstance() on TYPO3 v14.
     */
    public function __construct(
        private readonly ?FormPersistenceManagerInterface $formPersistenceManager = null,
    ) {
    }

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

        if ($majorVersion >= 14) {
            $formDefinitions = $this->loadAllFormDefinitionsV14($formPersistenceManager);
        } elseif ($majorVersion >= 13) {
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
        if ($this->formPersistenceManager !== null) {
            return $this->formPersistenceManager;
        }

        // Fallback for instantiation outside the DI container. The interface is only
        // publicly resolvable via makeInstance() on TYPO3 v12/v13; on v14 it is a private
        // alias and makeInstance() throws an \Error, so we degrade gracefully to null.
        try {
            return GeneralUtility::makeInstance(FormPersistenceManagerInterface::class);
        } catch (\Throwable) {
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
        $extFormConfigurationManager = GeneralUtility::makeInstance(ExtFormConfigurationManagerInterface::class);

        $typoScriptSettings = $this->getFormTypoScriptSettings();
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
     * Load all form definitions using the TYPO3 v14+ API.
     *
     * In v14, listForms() additionally requires a SearchCriteria argument and
     * load() no longer accepts the resolved formSettings (the second argument
     * is now the typoScriptSettings).
     *
     * @return array[]
     */
    private function loadAllFormDefinitionsV14(FormPersistenceManagerInterface $formPersistenceManager): array
    {
        $extFormConfigurationManager = GeneralUtility::makeInstance(ExtFormConfigurationManagerInterface::class);

        $typoScriptSettings = $this->getFormTypoScriptSettings();
        $formSettings = $extFormConfigurationManager->getYamlConfiguration($typoScriptSettings, false);

        try {
            $forms = $formPersistenceManager->listForms($formSettings, new SearchCriteria());
        } catch (\Exception) {
            return [];
        }

        $definitions = [];
        foreach (array_column($forms, 'persistenceIdentifier') as $identifier) {
            try {
                $definitions[] = $formPersistenceManager->load($identifier, $typoScriptSettings);
            } catch (\Exception) {
                // Skip forms that cannot be loaded
            }
        }
        return $definitions;
    }

    /**
     * Resolve plugin.tx_form.settings (TypoScript) for EXT:form.
     *
     * The Extbase ConfigurationManager requires a request since v13 and throws
     * NoServerRequestGivenException without one. This provider also runs in CLI
     * (scheduler task, mail:sender:validate), where no request exists, so we mirror what
     * EXT:form does in DataStructureIdentifierListener: use the global request if there is
     * one, otherwise fake a backend request. If TypoScript still cannot be resolved we
     * fall back to no TypoScript settings - the YAML defaults alone are enough to list
     * and load the form definitions.
     */
    private function getFormTypoScriptSettings(): array
    {
        $configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);

        $request = ($GLOBALS['TYPO3_REQUEST'] ?? null) instanceof ServerRequestInterface
            ? $GLOBALS['TYPO3_REQUEST']
            : (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $configurationManager->setRequest($request);

        try {
            return $configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
                'form'
            );
        } catch (\Throwable) {
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
