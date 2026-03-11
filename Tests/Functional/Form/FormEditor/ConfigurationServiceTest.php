<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Functional\Form\FormEditor;

use Hn\MailSender\Form\FormEditor\ConfigurationServiceDecoratorV12;
use Hn\MailSender\Form\FormEditor\ConfigurationServiceDecoratorV13;
use Hn\MailSender\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Form\Domain\Configuration\ConfigurationService;

class ConfigurationServiceTest extends AbstractFunctionalTest
{
    protected array $coreExtensionsToLoad = [
        'typo3/cms-form'
    ];

    public function testConfigurationServiceResolvesToCorrectDecoratorForCurrentTypo3Version(): void
    {
        $majorVersion = (new Typo3Version())->getMajorVersion();
        $service = $this->get(ConfigurationService::class);

        if ($majorVersion >= 13) {
            self::assertInstanceOf(ConfigurationServiceDecoratorV13::class, $service);
        } else {
            self::assertInstanceOf(ConfigurationServiceDecoratorV12::class, $service);
        }
    }

    public function testUnusedDecoratorVersionIsNotRegisteredInContainer(): void
    {
        $majorVersion = (new Typo3Version())->getMajorVersion();
        $container = GeneralUtility::getContainer();

        if ($majorVersion >= 13) {
            self::assertFalse($container->has(ConfigurationServiceDecoratorV12::class));
        } else {
            self::assertFalse($container->has(ConfigurationServiceDecoratorV13::class));
        }
    }
}
