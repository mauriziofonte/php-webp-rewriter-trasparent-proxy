<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\OriginRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\OriginRequest
 */
#[CoversClass(OriginRequest::class)]
final class OriginRequestTest extends TestCase
{
    public function testItCarriesTheUrlAndTheHeaders(): void
    {
        $request = new OriginRequest('https://origin.example.test/a.css?v=1', ['Referer' => 'https://cdn.example.test']);

        self::assertSame('https://origin.example.test/a.css?v=1', $request->getUrl());
        self::assertSame(['Referer' => 'https://cdn.example.test'], $request->getHeaders());
    }

    public function testHeadersAreOptional(): void
    {
        self::assertSame([], (new OriginRequest('https://origin.example.test/a.css'))->getHeaders());
    }
}
