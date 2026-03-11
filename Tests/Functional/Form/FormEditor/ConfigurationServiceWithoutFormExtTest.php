<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Functional\Form\FormEditor;

use Hn\MailSender\Form\FormEditor\ConfigurationServiceDecoratorV12;
use Hn\MailSender\Form\FormEditor\ConfigurationServiceDecoratorV13;
use Hn\MailSender\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Form\Domain\Configuration\ConfigurationService;

class ConfigurationServiceWithoutFormExtTest extends AbstractFunctionalTest
{

    public function testConfigurationServicesNotRegistered(): void
    {
        $this->assertFalse($this->has(ConfigurationService::class));
        $this->assertFalse($this->has(ConfigurationServiceDecoratorV12::class));
        $this->assertFalse($this->has(ConfigurationServiceDecoratorV13::class));
    }

}
