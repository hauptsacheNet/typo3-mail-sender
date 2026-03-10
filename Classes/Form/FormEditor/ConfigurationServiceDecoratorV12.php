<?php

declare(strict_types=1);

namespace Hn\MailSender\Form\FormEditor;

use TYPO3\CMS\Form\Domain\Configuration\ConfigurationService;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as ExtFormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

/**
 * TYPO3 v12 implementation: injects validated sender addresses into the form editor.
 *
 * Registered as the ConfigurationService implementation via Configuration/Services.php
 * when running on TYPO3 v12.
 */
class ConfigurationServiceDecoratorV12 extends ConfigurationService
{
    use ConfigurationServiceDecoratorTrait;

    public function __construct(
        private SenderAddressOptionsProvider $optionsProvider,
        private FormPersistenceManagerInterface $formPersistenceManager,
        ExtFormConfigurationManagerInterface $extFormConfigurationManager,
    ) {
        // v12 ConfigurationService constructor takes a single ConfigurationManagerInterface
        parent::__construct($extFormConfigurationManager);
    }

    protected function loadFormDefinition(string $identifier): ?array
    {
        // v12: FormPersistenceManagerInterface::load() takes only the identifier
        return $this->formPersistenceManager->load($identifier);
    }
}
