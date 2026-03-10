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

    $majorVersion = (new Typo3Version())->getMajorVersion();
    if ($majorVersion >= 13) {
        $containerBuilder->register(ConfigurationServiceDecoratorV13::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
        $containerBuilder->setAlias(ConfigurationService::class, ConfigurationServiceDecoratorV13::class)
            ->setPublic(true);
    } else {
        $containerBuilder->register(ConfigurationServiceDecoratorV12::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
        $containerBuilder->setAlias(ConfigurationService::class, ConfigurationServiceDecoratorV12::class)
            ->setPublic(true);
    }
};
