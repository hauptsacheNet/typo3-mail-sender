<?php

declare(strict_types=1);

namespace Hn\MailSender\Form\FormEditor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface as ExtbaseConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\Configuration\ConfigurationService;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as ExtFormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;
use TYPO3\CMS\Form\Service\TranslationService;

/**
 * TYPO3 v13 implementation: injects validated sender addresses into the form editor.
 *
 * Registered as the ConfigurationService implementation via Configuration/Services.php
 * when running on TYPO3 v13.
 */
class ConfigurationServiceDecoratorV13 extends ConfigurationService
{
    use ConfigurationServiceDecoratorTrait;

    public function __construct(
        private SenderAddressOptionsProvider $optionsProvider,
        private FormPersistenceManagerInterface $formPersistenceManager,
        private ExtbaseConfigurationManagerInterface $extbaseConfigurationManager,
        ExtFormConfigurationManagerInterface $extFormConfigurationManager,
        TranslationService $translationService,
        #[Autowire(service: 'cache.assets')]
        FrontendInterface $assetsCache,
        #[Autowire(service: 'cache.runtime')]
        FrontendInterface $runtimeCache,
    ) {
        // v13 ConfigurationService constructor takes 5 parameters
        parent::__construct(
            $extbaseConfigurationManager,
            $extFormConfigurationManager,
            $translationService,
            $assetsCache,
            $runtimeCache
        );
    }

    protected function loadFormDefinition(string $identifier): ?array
    {
        // v13: FormPersistenceManagerInterface::load() requires formSettings and typoScriptSettings
        $formSettings = $this->extbaseConfigurationManager->getConfiguration(
            ExtbaseConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'form'
        ) ?? [];
        $typoScriptSettings = $this->extbaseConfigurationManager->getConfiguration(
            ExtbaseConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        ) ?? [];

        return $this->formPersistenceManager->load($identifier, $formSettings, $typoScriptSettings);
    }
}
