<?php

declare(strict_types=1);

use Hn\MailSender\Form\FormEditor\ConfigurationServiceDecoratorV12;
use Hn\MailSender\Form\FormEditor\ConfigurationServiceDecoratorV13;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Form\Domain\Configuration\ConfigurationService;

return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    // Only register the decorator if the Form extension is available
    if (!class_exists(ConfigurationService::class)) {
        return;
    }

    $services = $configurator->services()
        ->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->private();

    $major = (new Typo3Version())->getMajorVersion();

    if ($major >= 13) {
        $services->set(ConfigurationServiceDecoratorV13::class);
        $containerBuilder->setAlias(ConfigurationService::class, ConfigurationServiceDecoratorV13::class)
            ->setPublic(true);
    } else {
        $services->set(ConfigurationServiceDecoratorV12::class);
        $containerBuilder->setAlias(ConfigurationService::class, ConfigurationServiceDecoratorV12::class)
            ->setPublic(true);
    }
};
