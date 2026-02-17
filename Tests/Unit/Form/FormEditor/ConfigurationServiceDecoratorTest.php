<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Unit\Form\FormEditor;

use Hn\MailSender\Form\FormEditor\ConfigurationServiceDecorator;
use Hn\MailSender\Form\FormEditor\SenderAddressOptionsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface as ExtbaseConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\Configuration\ConfigurationService;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as ExtFormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;
use TYPO3\CMS\Form\Service\TranslationService;

/**
 * Test case for ConfigurationServiceDecorator
 *
 * Verifies that sender address selectOptions always include addresses from
 * the current form definition, even during save requests where the persisted
 * form cannot be loaded (preventing HMAC validation errors).
 */
class ConfigurationServiceDecoratorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ConfigurationService::class)) {
            self::markTestSkipped('typo3/cms-form is not installed');
        }
    }

    /**
     * Build a decorator instance with the given mocks/stubs.
     *
     * @param array $prototypeConfiguration The prototype configuration returned by the inner service
     * @param array $dbSelectOptions Select options returned by the options provider
     * @param ServerRequestInterface|null $request Simulated TYPO3_REQUEST
     * @param array|null $persistedFormDefinition Form definition returned by formPersistenceManager->load()
     */
    private function buildDecorator(
        array $prototypeConfiguration,
        array $dbSelectOptions,
        ?ServerRequestInterface $request = null,
        ?array $persistedFormDefinition = null,
    ): ConfigurationServiceDecorator {
        $inner = $this->createMock(ConfigurationService::class);
        $inner->method('getPrototypeConfiguration')->willReturn($prototypeConfiguration);

        $optionsProvider = $this->createMock(SenderAddressOptionsProvider::class);
        $optionsProvider->method('getSelectOptions')->willReturn($dbSelectOptions);

        $formPersistenceManager = $this->createMock(FormPersistenceManagerInterface::class);
        if ($persistedFormDefinition !== null) {
            $formPersistenceManager->method('load')->willReturn($persistedFormDefinition);
        } else {
            $formPersistenceManager->method('load')->willThrowException(new \RuntimeException('Not available'));
        }

        $extbaseConfigManager = $this->createMock(ExtbaseConfigurationManagerInterface::class);
        $extbaseConfigManager->method('getConfiguration')->willReturn([]);

        $extFormConfigManager = $this->createMock(ExtFormConfigurationManagerInterface::class);
        $translationService = $this->createMock(TranslationService::class);
        $assetsCache = $this->createMock(FrontendInterface::class);
        $runtimeCache = $this->createMock(FrontendInterface::class);

        // Set the global request
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return new ConfigurationServiceDecorator(
            $inner,
            $optionsProvider,
            $formPersistenceManager,
            $extbaseConfigManager,
            $extFormConfigManager,
            $translationService,
            $assetsCache,
            $runtimeCache,
        );
    }

    /**
     * Build a minimal prototype configuration with senderAddress TextEditors
     * for the given finisher identifiers.
     */
    private function buildPrototypeConfig(array $finisherIdentifiers = ['EmailToSender']): array
    {
        $finishers = [];
        foreach ($finisherIdentifiers as $index => $identifier) {
            $finishers[($index + 1) * 10] = [
                'identifier' => $identifier,
                'editors' => [
                    100 => [
                        'identifier' => 'header',
                        'templateName' => 'Inspector-CollectionElementHeaderEditor',
                    ],
                    500 => [
                        'identifier' => 'senderAddress',
                        'templateName' => 'Inspector-TextEditor',
                        'propertyPath' => 'options.senderAddress',
                        'label' => 'Sender address',
                        'propertyValidators' => [10 => 'NaiveEmail'],
                        'propertyValidatorsMode' => 'OR',
                        'enableFormelementSelectionButton' => true,
                        'fieldExplanationText' => 'Enter sender address',
                    ],
                ],
            ];
        }

        return [
            'formElementsDefinition' => [
                'Form' => [
                    'formEditor' => [
                        'propertyCollections' => [
                            'finishers' => $finishers,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function buildRequest(?string $formPersistenceIdentifier = null, ?string $formDefinitionJson = null): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);

        $queryParams = [];
        if ($formPersistenceIdentifier !== null) {
            $queryParams['formPersistenceIdentifier'] = $formPersistenceIdentifier;
        }
        $request->method('getQueryParams')->willReturn($queryParams);

        $parsedBody = [];
        if ($formDefinitionJson !== null) {
            $parsedBody['formDefinition'] = $formDefinitionJson;
        }
        $request->method('getParsedBody')->willReturn($parsedBody);

        return $request;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    public function testEditorIsChangedToSingleSelectWithDatabaseOptions(): void
    {
        $dbOptions = [
            10 => ['value' => '', 'label' => '--- Select ---'],
            20 => ['value' => 'valid@example.com', 'label' => 'valid@example.com'],
        ];

        $decorator = $this->buildDecorator(
            $this->buildPrototypeConfig(),
            $dbOptions,
        );

        $config = $decorator->getPrototypeConfiguration('standard');
        $editor = $config['formElementsDefinition']['Form']['formEditor']['propertyCollections']['finishers'][10]['editors'][500];

        self::assertSame('Inspector-SingleSelectEditor', $editor['templateName']);
        self::assertSame($dbOptions, $editor['selectOptions']);
        self::assertArrayNotHasKey('propertyValidators', $editor);
        self::assertArrayNotHasKey('enableFormelementSelectionButton', $editor);
    }

    public function testExistingAddressFromPersistedFormIsAddedToOptions(): void
    {
        $dbOptions = [
            10 => ['value' => '', 'label' => '--- Select ---'],
            20 => ['value' => 'valid@example.com', 'label' => 'valid@example.com'],
        ];

        $persistedForm = [
            'finishers' => [
                ['identifier' => 'EmailToSender', 'options' => ['senderAddress' => 'old@example.com']],
            ],
        ];

        $decorator = $this->buildDecorator(
            $this->buildPrototypeConfig(),
            $dbOptions,
            $this->buildRequest('1:/form_definitions/contact.form.yaml'),
            $persistedForm,
        );

        $config = $decorator->getPrototypeConfiguration('standard');
        $selectOptions = $config['formElementsDefinition']['Form']['formEditor']['propertyCollections']['finishers'][10]['editors'][500]['selectOptions'];

        $values = array_column($selectOptions, 'value');
        self::assertContains('old@example.com', $values, 'Existing address from persisted form must be in selectOptions');
        self::assertContains('valid@example.com', $values, 'Validated database address must be in selectOptions');
    }

    /**
     * This is the key test: during save, if the persisted form can't be loaded,
     * the decorator must fall back to parsing the submitted form definition from
     * the request body. Without this, the sender address value won't be in
     * selectOptions and TYPO3's HMAC validation fails with #1528591585.
     */
    public function testSubmittedFormDefinitionIsUsedAsFallbackDuringSave(): void
    {
        $dbOptions = [
            10 => ['value' => '', 'label' => '--- Select ---'],
            20 => ['value' => 'valid@example.com', 'label' => 'valid@example.com'],
        ];

        $submittedFormJson = json_encode([
            'type' => 'Form',
            'identifier' => 'contactForm',
            'finishers' => [
                ['identifier' => 'EmailToSender', 'options' => ['senderAddress' => 'submitted@example.com']],
            ],
        ]);

        // No formPersistenceIdentifier in query (simulating save AJAX),
        // persisted form load throws, but formDefinition is in POST body
        $decorator = $this->buildDecorator(
            $this->buildPrototypeConfig(),
            $dbOptions,
            $this->buildRequest(null, $submittedFormJson),
            null, // persisted form not available
        );

        $config = $decorator->getPrototypeConfiguration('standard');
        $selectOptions = $config['formElementsDefinition']['Form']['formEditor']['propertyCollections']['finishers'][10]['editors'][500]['selectOptions'];

        $values = array_column($selectOptions, 'value');
        self::assertContains('submitted@example.com', $values, 'Submitted sender address must be in selectOptions to prevent HMAC error #1528591585');
    }

    public function testAlreadyValidatedAddressIsNotDuplicated(): void
    {
        $dbOptions = [
            10 => ['value' => '', 'label' => '--- Select ---'],
            20 => ['value' => 'valid@example.com', 'label' => 'valid@example.com'],
        ];

        $submittedFormJson = json_encode([
            'finishers' => [
                ['identifier' => 'EmailToSender', 'options' => ['senderAddress' => 'valid@example.com']],
            ],
        ]);

        $decorator = $this->buildDecorator(
            $this->buildPrototypeConfig(),
            $dbOptions,
            $this->buildRequest(null, $submittedFormJson),
            null,
        );

        $config = $decorator->getPrototypeConfiguration('standard');
        $selectOptions = $config['formElementsDefinition']['Form']['formEditor']['propertyCollections']['finishers'][10]['editors'][500]['selectOptions'];

        $values = array_column($selectOptions, 'value');
        $count = count(array_filter($values, fn($v) => $v === 'valid@example.com'));
        self::assertSame(1, $count, 'Already validated address should not be duplicated');
    }

    public function testNoRequestAvailableReturnsDbOptionsOnly(): void
    {
        $dbOptions = [
            10 => ['value' => '', 'label' => '--- Select ---'],
            20 => ['value' => 'valid@example.com', 'label' => 'valid@example.com'],
        ];

        $decorator = $this->buildDecorator(
            $this->buildPrototypeConfig(),
            $dbOptions,
            null, // no request
        );

        $config = $decorator->getPrototypeConfiguration('standard');
        $selectOptions = $config['formElementsDefinition']['Form']['formEditor']['propertyCollections']['finishers'][10]['editors'][500]['selectOptions'];

        self::assertSame($dbOptions, $selectOptions);
    }

    public function testBothEmailFinishersGetSelectOptions(): void
    {
        $dbOptions = [
            10 => ['value' => '', 'label' => '--- Select ---'],
            20 => ['value' => 'valid@example.com', 'label' => 'valid@example.com'],
        ];

        $decorator = $this->buildDecorator(
            $this->buildPrototypeConfig(['EmailToSender', 'EmailToReceiver']),
            $dbOptions,
        );

        $config = $decorator->getPrototypeConfiguration('standard');
        $finishers = $config['formElementsDefinition']['Form']['formEditor']['propertyCollections']['finishers'];

        foreach ($finishers as $finisher) {
            $editor = $finisher['editors'][500];
            self::assertSame('Inspector-SingleSelectEditor', $editor['templateName'], 'Finisher ' . $finisher['identifier'] . ' should have SingleSelectEditor');
            self::assertArrayHasKey('selectOptions', $editor);
        }
    }
}
