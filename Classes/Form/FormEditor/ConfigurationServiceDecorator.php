<?php

declare(strict_types=1);

namespace Hn\MailSender\Form\FormEditor;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface as ExtbaseConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\Configuration\ConfigurationService;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as ExtFormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;
use TYPO3\CMS\Form\Service\TranslationService;

/**
 * Decorator for the Form ConfigurationService that injects
 * sender addresses into the form editor's selectOptions.
 *
 * This allows the form editor to show a dropdown of validated sender
 * addresses instead of a free text field.
 */
#[AsDecorator(decorates: ConfigurationService::class)]
class ConfigurationServiceDecorator extends ConfigurationService
{
    private ConfigurationService $inner;
    private SenderAddressOptionsProvider $optionsProvider;
    private FormPersistenceManagerInterface $formPersistenceManager;
    protected ExtbaseConfigurationManagerInterface $extbaseConfigurationManager;

    public function __construct(
        #[AutowireDecorated]
        ConfigurationService $inner,
        SenderAddressOptionsProvider $optionsProvider,
        FormPersistenceManagerInterface $formPersistenceManager,
        ExtbaseConfigurationManagerInterface $extbaseConfigurationManager,
        ExtFormConfigurationManagerInterface $extFormConfigurationManager,
        TranslationService $translationService,
        #[Autowire(service: 'cache.assets')]
        FrontendInterface $assetsCache,
        #[Autowire(service: 'cache.runtime')]
        FrontendInterface $runtimeCache,
    ) {
        parent::__construct(
            $extbaseConfigurationManager,
            $extFormConfigurationManager,
            $translationService,
            $assetsCache,
            $runtimeCache
        );
        $this->inner = $inner;
        $this->optionsProvider = $optionsProvider;
        $this->formPersistenceManager = $formPersistenceManager;
        $this->extbaseConfigurationManager = $extbaseConfigurationManager;
    }

    /**
     * Get the prototype configuration with injected sender address options
     */
    public function getPrototypeConfiguration(string $prototypeName): array
    {
        $configuration = $this->inner->getPrototypeConfiguration($prototypeName);

        // Get validated sender addresses
        $senderAddressOptions = $this->optionsProvider->getSelectOptions();

        // Add any existing sender addresses from the current form that aren't in the validated list
        $senderAddressOptions = $this->addExistingSenderAddresses($senderAddressOptions);

        // Find and modify senderAddress editors in finisher configurations
        $configuration = $this->injectSenderAddressOptions($configuration, $senderAddressOptions);

        return $configuration;
    }

    /**
     * Add existing sender addresses from the current form definition
     * that are not in the validated list (to prevent data loss and to
     * ensure HMAC validation passes during save).
     *
     * TYPO3's form framework validates SingleSelectEditor values against
     * the selectOptions list. If a value is not in selectOptions, it falls
     * through to HMAC validation, which fails for finisher option properties
     * (TYPO3 does not generate HMACs for them). To prevent this, we must
     * ensure that any existing sender address in the form definition is
     * always present in selectOptions.
     */
    private function addExistingSenderAddresses(array $selectOptions): array
    {
        $formDefinition = $this->loadCurrentFormDefinition();

        if ($formDefinition === null) {
            // During save (AJAX POST), loading the persisted form may fail because
            // Extbase/TypoScript configuration is not fully available. Fall back to
            // parsing the submitted form definition from the request body so that
            // its sender address values are included in selectOptions. Without this,
            // TYPO3's CreatablePropertyCollectionElementPropertiesValidator rejects
            // the value with "No hmac found for property options.senderAddress".
            $formDefinition = $this->parseSubmittedFormDefinition();
        }

        if ($formDefinition === null) {
            return $selectOptions;
        }

        // Extract existing sender addresses from finishers
        $existingAddresses = $this->extractSenderAddressesFromFinishers($formDefinition);

        // Get list of values already in options
        $existingValues = array_column($selectOptions, 'value');

        // Add any addresses that aren't already in the options
        $index = 1000; // Start at high index to not conflict with validated addresses
        foreach ($existingAddresses as $address) {
            if ($address !== '' && !in_array($address, $existingValues, true)) {
                $selectOptions[$index] = [
                    'value' => $address,
                    'label' => $address . ' (not validated)',
                ];
                $existingValues[] = $address;
                $index += 10;
            }
        }

        return $selectOptions;
    }

    /**
     * Load the current form definition from the request
     */
    private function loadCurrentFormDefinition(): ?array
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        // Try to get formPersistenceIdentifier from query params or parsed body
        $queryParams = $request->getQueryParams();
        $formPersistenceIdentifier = $queryParams['formPersistenceIdentifier'] ?? null;

        if ($formPersistenceIdentifier === null) {
            $parsedBody = $request->getParsedBody();
            $formPersistenceIdentifier = $parsedBody['formPersistenceIdentifier'] ?? null;
        }

        if ($formPersistenceIdentifier === null) {
            return null;
        }

        try {
            // Get form settings from extbase configuration manager
            $formSettings = $this->extbaseConfigurationManager->getConfiguration(
                ExtbaseConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
                'form'
            ) ?? [];

            // Get TypoScript settings
            $typoScriptSettings = $this->extbaseConfigurationManager->getConfiguration(
                ExtbaseConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
            ) ?? [];

            return $this->formPersistenceManager->load(
                $formPersistenceIdentifier,
                $formSettings,
                $typoScriptSettings
            );
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse the submitted form definition from the request body.
     *
     * During save, the form editor sends the form definition as a JSON string
     * in the request body (field "formDefinition"). We parse it to extract
     * sender address values so they can be included in selectOptions.
     */
    private function parseSubmittedFormDefinition(): ?array
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            return null;
        }

        $formDefinitionJson = $parsedBody['formDefinition'] ?? null;
        if ($formDefinitionJson === null || !is_string($formDefinitionJson)) {
            return null;
        }

        try {
            $formDefinition = json_decode($formDefinitionJson, true, 512, JSON_THROW_ON_ERROR);
            return is_array($formDefinition) ? $formDefinition : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Extract sender addresses from form finishers
     */
    private function extractSenderAddressesFromFinishers(array $formDefinition): array
    {
        $addresses = [];

        $finishers = $formDefinition['finishers'] ?? [];
        foreach ($finishers as $finisher) {
            $senderAddress = $finisher['options']['senderAddress'] ?? null;
            if ($senderAddress !== null && is_string($senderAddress)) {
                $addresses[] = $senderAddress;
            }
        }

        return $addresses;
    }

    /**
     * Inject sender address select options into the form editor configuration
     */
    private function injectSenderAddressOptions(array $configuration, array $selectOptions): array
    {
        // Check if we have the form element definition with finisher property collections
        if (!isset($configuration['formElementsDefinition']['Form']['formEditor']['propertyCollections']['finishers'])) {
            return $configuration;
        }

        $finishers = &$configuration['formElementsDefinition']['Form']['formEditor']['propertyCollections']['finishers'];

        foreach ($finishers as &$finisher) {
            if (!isset($finisher['editors']) || !is_array($finisher['editors'])) {
                continue;
            }

            foreach ($finisher['editors'] as &$editor) {
                if (isset($editor['identifier']) && $editor['identifier'] === 'senderAddress') {
                    // Change from TextEditor to SingleSelectEditor
                    $editor['templateName'] = 'Inspector-SingleSelectEditor';
                    $editor['selectOptions'] = $selectOptions;

                    // Remove properties that don't apply to select fields
                    unset($editor['propertyValidators']);
                    unset($editor['propertyValidatorsMode']);
                    unset($editor['enableFormelementSelectionButton']);
                    unset($editor['fieldExplanationText']);
                }
            }
        }

        return $configuration;
    }

    // All other public methods are inherited from ConfigurationService
}
