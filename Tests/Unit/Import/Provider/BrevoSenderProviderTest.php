<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Unit\Import\Provider;

use GuzzleHttp\Psr7\Response;
use Hn\MailSender\Import\Provider\BrevoSenderProvider;
use Hn\MailSender\Import\ValueObject\SenderAddress;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Test case for BrevoSenderProvider
 */
class BrevoSenderProviderTest extends TestCase
{
    private const SAMPLE_RESPONSE = '{
        "senders": [
            {"id": 1, "name": "Marketing", "email": "marketing@example.com", "active": true},
            {"id": 2, "name": "Newsletter", "email": "newsletter@example.com", "active": false},
            {"id": 3, "name": "Support", "email": "support@example.com", "active": true}
        ]
    }';

    public function testReturnsEmptyAndSkipsHttpWhenNoApiKeyConfigured(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        $provider = new BrevoSenderProvider(
            $requestFactory,
            $this->stubExtensionConfiguration(''),
            $this->stubCacheManager($this->stubMissCache()),
        );

        self::assertSame([], $provider->getSenderAddresses());
    }

    public function testImportsOnlyActiveSenders(): void
    {
        // Capture the request arguments instead of asserting them via with(): the
        // provider catches \Throwable around the request, which would otherwise
        // swallow PHPUnit's own expectation failures.
        $captured = null;
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->willReturnCallback(
                function (string $uri, string $method, array $options) use (&$captured): Response {
                    $captured = ['uri' => $uri, 'method' => $method, 'options' => $options];
                    return new Response(200, [], self::SAMPLE_RESPONSE);
                }
            );

        $provider = new BrevoSenderProvider(
            $requestFactory,
            $this->stubExtensionConfiguration('xkeysib-secret'),
            $this->stubCacheManager($this->stubMissCache()),
        );

        $addresses = $provider->getSenderAddresses();

        // Correct endpoint, method and authentication header.
        self::assertSame('https://api.brevo.com/v3/senders', $captured['uri']);
        self::assertSame('GET', $captured['method']);
        self::assertSame('xkeysib-secret', $captured['options']['headers']['api-key']);

        // Only the two active senders are imported, the inactive one is skipped.
        self::assertCount(2, $addresses);
        self::assertContainsOnlyInstancesOf(SenderAddress::class, $addresses);
        self::assertSame('marketing@example.com', $addresses[0]->email);
        self::assertSame('Marketing', $addresses[0]->name);
        self::assertSame('support@example.com', $addresses[1]->email);
        self::assertSame('Support', $addresses[1]->name);
    }

    public function testUsesCachedResultWithoutCallingApi(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn([
            ['email' => 'cached@example.com', 'name' => 'Cached'],
        ]);
        $cache->expects(self::never())->method('set');

        $provider = new BrevoSenderProvider(
            $requestFactory,
            $this->stubExtensionConfiguration('xkeysib-secret'),
            $this->stubCacheManager($cache),
        );

        $addresses = $provider->getSenderAddresses();

        self::assertCount(1, $addresses);
        self::assertSame('cached@example.com', $addresses[0]->email);
        self::assertSame('Cached', $addresses[0]->name);
    }

    public function testCachesResultAfterFetching(): void
    {
        $requestFactory = $this->createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturn(new Response(200, [], self::SAMPLE_RESPONSE));

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cache->expects(self::once())
            ->method('set')
            ->with(
                self::stringStartsWith('brevo_senders_'),
                [
                    ['email' => 'marketing@example.com', 'name' => 'Marketing'],
                    ['email' => 'support@example.com', 'name' => 'Support'],
                ],
                [],
                60
            );

        $provider = new BrevoSenderProvider(
            $requestFactory,
            $this->stubExtensionConfiguration('xkeysib-secret'),
            $this->stubCacheManager($cache),
        );

        $provider->getSenderAddresses();
    }

    public function testReturnsEmptyOnApiError(): void
    {
        $requestFactory = $this->createStub(RequestFactory::class);
        $requestFactory->method('request')->willThrowException(new \RuntimeException('connection refused'));

        $provider = new BrevoSenderProvider(
            $requestFactory,
            $this->stubExtensionConfiguration('xkeysib-secret'),
            $this->stubCacheManager($this->stubMissCache()),
        );

        self::assertSame([], $provider->getSenderAddresses());
    }

    public function testReturnsEmptyOnNon200Response(): void
    {
        $requestFactory = $this->createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturn(new Response(401, [], '{"message":"Key not found"}'));

        $provider = new BrevoSenderProvider(
            $requestFactory,
            $this->stubExtensionConfiguration('xkeysib-secret'),
            $this->stubCacheManager($this->stubMissCache()),
        );

        self::assertSame([], $provider->getSenderAddresses());
    }

    private function stubExtensionConfiguration(string $apiKey): ExtensionConfiguration
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($apiKey);
        return $extensionConfiguration;
    }

    private function stubCacheManager(FrontendInterface $cache): CacheManager
    {
        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);
        return $cacheManager;
    }

    private function stubMissCache(): FrontendInterface
    {
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        return $cache;
    }
}
