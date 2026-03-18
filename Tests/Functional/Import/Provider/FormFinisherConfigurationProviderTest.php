<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Functional\Import\Provider;

use Hn\MailSender\Import\Provider\FormFinisherConfigurationProvider;
use Hn\MailSender\Import\ValueObject\SenderAddress;
use Hn\MailSender\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;

class FormFinisherConfigurationProviderTest extends AbstractFunctionalTest
{
    protected array $coreExtensionsToLoad = [
        'typo3/cms-form',
    ];

    private FormFinisherConfigurationProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new FormFinisherConfigurationProvider();
    }

    public function testGetNameReturnsExpectedValue(): void
    {
        self::assertSame('Form Finisher Configuration', $this->subject->getName());
    }

    public function testExtractSenderAddressesFromEmailToReceiverFinisher(): void
    {
        $formDefinition = [
            'finishers' => [
                [
                    'identifier' => 'EmailToReceiver',
                    'options' => [
                        'senderAddress' => 'sender@example.com',
                        'senderName' => 'Contact Form',
                    ],
                ],
            ],
        ];

        $addresses = $this->callExtractSenderAddresses($formDefinition);

        self::assertCount(1, $addresses);
        self::assertSame('sender@example.com', $addresses[0]->email);
        self::assertSame('Contact Form', $addresses[0]->name);
    }

    public function testExtractSenderAddressesFromEmailToSenderFinisher(): void
    {
        $formDefinition = [
            'finishers' => [
                [
                    'identifier' => 'EmailToSender',
                    'options' => [
                        'senderAddress' => 'noreply@example.com',
                        'senderName' => 'My Website',
                    ],
                ],
            ],
        ];

        $addresses = $this->callExtractSenderAddresses($formDefinition);

        self::assertCount(1, $addresses);
        self::assertSame('noreply@example.com', $addresses[0]->email);
        self::assertSame('My Website', $addresses[0]->name);
    }

    public function testExtractSenderAddressesFromMultipleFinishers(): void
    {
        $formDefinition = [
            'finishers' => [
                [
                    'identifier' => 'EmailToReceiver',
                    'options' => [
                        'senderAddress' => 'sender@example.com',
                        'senderName' => 'Contact Form',
                    ],
                ],
                [
                    'identifier' => 'EmailToSender',
                    'options' => [
                        'senderAddress' => 'noreply@example.com',
                        'senderName' => 'My Website',
                    ],
                ],
            ],
        ];

        $addresses = $this->callExtractSenderAddresses($formDefinition);

        self::assertCount(2, $addresses);
        self::assertSame('sender@example.com', $addresses[0]->email);
        self::assertSame('noreply@example.com', $addresses[1]->email);
    }

    public function testExtractSenderAddressesSkipsFormElementReferences(): void
    {
        $formDefinition = [
            'finishers' => [
                [
                    'identifier' => 'EmailToSender',
                    'options' => [
                        'senderAddress' => '{email}',
                        'senderName' => '{name}',
                    ],
                ],
            ],
        ];

        $addresses = $this->callExtractSenderAddresses($formDefinition);

        self::assertCount(0, $addresses);
    }

    public function testExtractSenderAddressesSkipsNonEmailFinishers(): void
    {
        $formDefinition = [
            'finishers' => [
                [
                    'identifier' => 'Redirect',
                    'options' => [
                        'pageUid' => 1,
                    ],
                ],
                [
                    'identifier' => 'EmailToReceiver',
                    'options' => [
                        'senderAddress' => 'sender@example.com',
                    ],
                ],
            ],
        ];

        $addresses = $this->callExtractSenderAddresses($formDefinition);

        self::assertCount(1, $addresses);
        self::assertSame('sender@example.com', $addresses[0]->email);
    }

    public function testExtractSenderAddressesHandlesEmptySenderAddress(): void
    {
        $formDefinition = [
            'finishers' => [
                [
                    'identifier' => 'EmailToReceiver',
                    'options' => [
                        'senderAddress' => '',
                    ],
                ],
            ],
        ];

        $addresses = $this->callExtractSenderAddresses($formDefinition);

        self::assertCount(0, $addresses);
    }

    public function testExtractSenderAddressesHandlesMissingSenderAddress(): void
    {
        $formDefinition = [
            'finishers' => [
                [
                    'identifier' => 'EmailToReceiver',
                    'options' => [
                        'subject' => 'Test',
                    ],
                ],
            ],
        ];

        $addresses = $this->callExtractSenderAddresses($formDefinition);

        self::assertCount(0, $addresses);
    }

    public function testExtractSenderAddressesHandlesNoFinishers(): void
    {
        $formDefinition = [];

        $addresses = $this->callExtractSenderAddresses($formDefinition);

        self::assertCount(0, $addresses);
    }

    public function testExtractSenderAddressesWithSenderNameAsElementReference(): void
    {
        $formDefinition = [
            'finishers' => [
                [
                    'identifier' => 'EmailToReceiver',
                    'options' => [
                        'senderAddress' => 'sender@example.com',
                        'senderName' => '{name}',
                    ],
                ],
            ],
        ];

        $addresses = $this->callExtractSenderAddresses($formDefinition);

        self::assertCount(1, $addresses);
        self::assertSame('sender@example.com', $addresses[0]->email);
        self::assertSame('', $addresses[0]->name);
    }

    public function testGetSenderAddressesDoesNotCrashWithFormExtensionLoaded(): void
    {
        // Provide a server request so ConfigurationManager can initialize
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        // This integration test verifies the provider doesn't crash
        // when the form extension is loaded (covers the TYPO3 12/13 compat fix)
        $addresses = $this->subject->getSenderAddresses();

        self::assertIsArray($addresses);
    }

    /**
     * Call the private extractSenderAddresses method via reflection
     *
     * @return SenderAddress[]
     */
    private function callExtractSenderAddresses(array $formDefinition): array
    {
        $reflection = new \ReflectionMethod(FormFinisherConfigurationProvider::class, 'extractSenderAddresses');

        return $reflection->invoke($this->subject, $formDefinition);
    }
}
