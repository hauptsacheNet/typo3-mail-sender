<?php

declare(strict_types=1);

namespace Hn\MailSender\Form\FormEditor;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared logic for TYPO3 version-specific ConfigurationService subclasses.
 *
 * Injects validated sender addresses into the form editor's selectOptions,
 * replacing the free-text senderAddress field with a dropdown.
 */
trait ConfigurationServiceDecoratorTrait
{
    abstract protected function loadFormDefinition(string $identifier): ?array;

    /**
     * Get the prototype configuration with injected sender address options
     */
    public function getPrototypeConfiguration(string $prototypeName): array
    {
        $configuration = parent::getPrototypeConfiguration($prototypeName);

        $senderAddressOptions = $this->optionsProvider->getSelectOptions();
        $senderAddressOptions = $this->addExistingSenderAddresses($senderAddressOptions);

        return $this->injectSenderAddressOptions($configuration, $senderAddressOptions);
    }

    /**
     * Add existing sender addresses from the current form definition
     * that are not in the validated list (to prevent data loss)
     */
    private function addExistingSenderAddresses(array $selectOptions): array
    {
        $formDefinition = $this->loadCurrentFormDefinition();
        if ($formDefinition === null) {
            return $selectOptions;
        }

        $existingAddresses = $this->extractSenderAddressesFromFinishers($formDefinition);
        $existingValues = array_column($selectOptions, 'value');

        $index = 1000;
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
            return $this->loadFormDefinition($formPersistenceIdentifier);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Extract sender addresses from form finishers
     */
    private function extractSenderAddressesFromFinishers(array $formDefinition): array
    {
        $addresses = [];

        foreach ($formDefinition['finishers'] ?? [] as $finisher) {
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
                    $editor['templateName'] = 'Inspector-SingleSelectEditor';
                    $editor['selectOptions'] = $selectOptions;

                    unset($editor['propertyValidators']);
                    unset($editor['propertyValidatorsMode']);
                    unset($editor['enableFormelementSelectionButton']);
                    unset($editor['fieldExplanationText']);
                }
            }
        }

        return $configuration;
    }
}
