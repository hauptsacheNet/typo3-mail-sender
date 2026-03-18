<?php

declare(strict_types=1);

namespace Hn\MailSender\Tests\Unit\Service;

use Hn\MailSender\Service\EmlParserService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Test case for EmlParserService
 */
class EmlParserServiceTest extends TestCase
{
    private EmlParserService $service;

    protected function setUp(): void
    {
        $this->service = new EmlParserService();
    }

    public function testCanParseEmlWithAuthenticationResults(): void
    {
        // Load the actual test EML file
        $emlPath = __DIR__ . '/../../../Test Email from info@tutti-fagotti.com.eml';

        if (!file_exists($emlPath)) {
            self::markTestSkipped('Test EML file not found at ' . $emlPath);
        }

        $content = file_get_contents($emlPath);

        // Create mock file
        $file = $this->createMock(FileInterface::class);
        $file->method('getContents')->willReturn($content);

        $result = $this->service->parse($file);

        // Test basic structure
        self::assertArrayHasKey('file_hash', $result);
        self::assertArrayHasKey('from', $result);
        self::assertArrayHasKey('authentication_results', $result);
        self::assertArrayHasKey('dkim_signature', $result);
        self::assertArrayHasKey('received_chain', $result);

        // Test From header parsing
        self::assertSame('info@tutti-fagotti.com', $result['from']['email']);
        self::assertSame('Tutti Fagotti', $result['from']['name']);

        // Test Authentication-Results parsing
        $authResults = $result['authentication_results'];
        self::assertNotNull($authResults['raw'], 'Raw authentication results should be present');

        // Test SPF result
        self::assertNotNull($authResults['spf'], 'SPF result should be extracted');
        self::assertSame('pass', $authResults['spf']['result']);

        // Test DKIM result
        self::assertNotNull($authResults['dkim'], 'DKIM result should be extracted');
        self::assertSame('pass', $authResults['dkim']['result']);
        self::assertSame('mail', $authResults['dkim']['selector']);
        self::assertSame('tutti-fagotti.com', $authResults['dkim']['domain']);

        // Test DMARC result
        self::assertNotNull($authResults['dmarc'], 'DMARC result should be extracted');
        self::assertSame('pass', $authResults['dmarc']['result']);

        // Test DKIM signature parsing
        $dkimSig = $result['dkim_signature'];
        self::assertNotNull($dkimSig, 'DKIM signature should be extracted');
        self::assertSame('tutti-fagotti.com', $dkimSig['domain']);
        self::assertSame('mail', $dkimSig['selector']);
        self::assertSame('rsa-sha256', $dkimSig['algorithm']);
    }

    public function testParsesMultipleDkimResultsInSingleAuthHeader(): void
    {
        $emlContent = <<<EML
From: sender@leibniz-ipn.de
To: recipient@example.com
Subject: Test
Date: Mon, 01 Dec 2025 12:00:00 +0000
Authentication-Results: mx.google.com;
        dkim=pass header.i=@leibniz-ipn.de header.s=20250116rsa header.b=UjOC2xU4;
        dkim=neutral (no key) header.i=@leibniz-ipn.de;
        spf=pass smtp.mailfrom=sender@leibniz-ipn.de;
        dmarc=pass header.from=leibniz-ipn.de
DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed;
        d=leibniz-ipn.de; s=20250116rsa; h=From:To:Subject;
        bh=abc123; b=sig1
DKIM-Signature: v=1; a=ed25519-sha256; c=relaxed/relaxed;
        d=leibniz-ipn.de; s=20250116ed25519; h=From:To:Subject;
        bh=abc123; b=sig2

Test body
EML;

        $file = $this->createMock(FileInterface::class);
        $file->method('getContents')->willReturn($emlContent);

        $result = $this->service->parse($file);
        $authResults = $result['authentication_results'];

        // The singular 'dkim' key should have the 'pass' result (best)
        self::assertNotNull($authResults['dkim']);
        self::assertSame('pass', $authResults['dkim']['result']);
        self::assertSame('20250116rsa', $authResults['dkim']['selector']);

        // The 'dkim_results' key should have both results
        self::assertArrayHasKey('dkim_results', $authResults);
        self::assertCount(2, $authResults['dkim_results']);
        self::assertSame('pass', $authResults['dkim_results'][0]['result']);
        self::assertSame('neutral', $authResults['dkim_results'][1]['result']);

        // Should parse both DKIM-Signature headers
        self::assertArrayHasKey('dkim_signatures', $result);
        self::assertCount(2, $result['dkim_signatures']);
        self::assertSame('20250116rsa', $result['dkim_signatures'][0]['selector']);
        self::assertSame('rsa-sha256', $result['dkim_signatures'][0]['algorithm']);
        self::assertSame('20250116ed25519', $result['dkim_signatures'][1]['selector']);
        self::assertSame('ed25519-sha256', $result['dkim_signatures'][1]['algorithm']);
    }

    public function testDkimPassPreferredOverNeutralRegardlessOfOrder(): void
    {
        // Neutral appears BEFORE pass — pass should still be selected
        $emlContent = <<<EML
From: sender@example.com
To: recipient@example.com
Subject: Test
Date: Mon, 01 Dec 2025 12:00:00 +0000
Authentication-Results: mx.example.com;
        dkim=neutral (no key) header.i=@example.com;
        dkim=pass header.i=@example.com header.s=selector1

Test body
EML;

        $file = $this->createMock(FileInterface::class);
        $file->method('getContents')->willReturn($emlContent);

        $result = $this->service->parse($file);
        $authResults = $result['authentication_results'];

        self::assertSame('pass', $authResults['dkim']['result']);
        self::assertSame('selector1', $authResults['dkim']['selector']);
        self::assertCount(2, $authResults['dkim_results']);
    }

    public function testDeduplicatesDkimResultsFromAuthAndArcHeaders(): void
    {
        // Same DKIM results in both Authentication-Results and ARC-Authentication-Results
        $emlContent = <<<EML
From: sender@leibniz-ipn.de
To: recipient@example.com
Subject: Test
Date: Mon, 01 Dec 2025 12:00:00 +0000
Authentication-Results: mx.google.com;
        dkim=pass header.i=@leibniz-ipn.de header.s=20250116rsa header.b=UjOC2xU4;
        dkim=neutral (no key) header.i=@leibniz-ipn.de
ARC-Authentication-Results: i=1; mx.google.com;
        dkim=pass header.i=@leibniz-ipn.de header.s=20250116rsa header.b=UjOC2xU4;
        dkim=neutral (no key) header.i=@leibniz-ipn.de

Test body
EML;

        $file = $this->createMock(FileInterface::class);
        $file->method('getContents')->willReturn($emlContent);

        $result = $this->service->parse($file);
        $authResults = $result['authentication_results'];

        // Should have exactly 2 DKIM results (not 4 duplicated)
        self::assertCount(2, $authResults['dkim_results']);
        self::assertSame('pass', $authResults['dkim_results'][0]['result']);
        self::assertSame('neutral', $authResults['dkim_results'][1]['result']);
    }

    public function testCanParseEmlWithoutAuthenticationResults(): void
    {
        // Simple EML without authentication headers
        $emlContent = <<<EML
From: "Test User" <test@example.com>
To: recipient@example.com
Subject: Test Email
Date: Mon, 01 Dec 2025 12:00:00 +0000
Content-Type: text/plain

This is a test email.
EML;

        $file = $this->createMock(FileInterface::class);
        $file->method('getContents')->willReturn($emlContent);

        $result = $this->service->parse($file);

        self::assertSame('test@example.com', $result['from']['email']);
        self::assertSame('Test User', $result['from']['name']);
        self::assertNull($result['authentication_results']['spf']);
        self::assertNull($result['authentication_results']['dkim']);
        self::assertEmpty($result['authentication_results']['dkim_results']);
        self::assertNull($result['authentication_results']['dmarc']);
        self::assertNull($result['dkim_signature']);
        self::assertEmpty($result['dkim_signatures']);
    }

    public function testCanParseDkimSignatureHeader(): void
    {
        // EML with only DKIM-Signature (no Authentication-Results)
        $emlContent = <<<EML
From: sender@example.com
To: recipient@example.com
Subject: Test
Date: Mon, 01 Dec 2025 12:00:00 +0000
DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=example.com;
 q=dns/txt; s=selector1; bh=47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=;
 h=from:to:subject:date;
 b=dummysignature123456789

Test body
EML;

        $file = $this->createMock(FileInterface::class);
        $file->method('getContents')->willReturn($emlContent);

        $result = $this->service->parse($file);

        self::assertNotNull($result['dkim_signature']);
        self::assertSame('example.com', $result['dkim_signature']['domain']);
        self::assertSame('selector1', $result['dkim_signature']['selector']);
        self::assertSame('rsa-sha256', $result['dkim_signature']['algorithm']);
        self::assertSame('1', $result['dkim_signature']['version']);
    }
}
