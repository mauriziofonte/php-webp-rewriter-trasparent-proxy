<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Http\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Http\RequestId
 */
#[CoversClass(RequestId::class)]
final class RequestIdTest extends TestCase
{
    /**
     * @dataProvider provideAdoptedIds
     */
    #[DataProvider('provideAdoptedIds')]
    public function testAValidIdIsAdopted(string $id): void
    {
        $request = Request::create('https://cdn.example.test/a.css', 'GET', [], [], [], ['HTTP_X_REQUEST_ID' => $id]);

        self::assertSame($id, RequestId::of($request));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideAdoptedIds(): array
    {
        return [
            'uuid' => ['3f2504e0-4f89-11d3-9a0c-0305e82c3301'],
            'nginx' => ['4bf92f3577b34da6a3ce929d0e0e4736'],
            'every allowed symbol' => ['a.b_c:d/e+f=g@h-i'],
            'one character' => ['x'],
            '128 characters' => [str_repeat('a', 128)],
        ];
    }

    /**
     * @dataProvider provideRefusedIds
     */
    #[DataProvider('provideRefusedIds')]
    public function testAnInvalidIdIsReplaced(string $id): void
    {
        $request = Request::create('https://cdn.example.test/a.css', 'GET', [], [], [], ['HTTP_X_REQUEST_ID' => $id]);

        $adopted = RequestId::of($request);

        self::assertNotSame($id, $adopted);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $adopted);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRefusedIds(): array
    {
        return [
            'empty' => [''],
            '129 characters' => [str_repeat('a', 129)],
            'space' => ['a b'],
            'quote' => ['a"b'],
            'semicolon' => ['a;b'],
            'line break' => ["a\nb"],
            'non-ASCII' => ["caf\u{e9}"],
        ];
    }

    public function testAnIdIsGeneratedWithoutHeader(): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', RequestId::of(Request::create('https://cdn.example.test/a.css')));
    }

    public function testTheIdIsFixedForTheRequest(): void
    {
        $request = Request::create('https://cdn.example.test/a.css');

        $first = RequestId::of($request);
        $request->headers->set(RequestId::HEADER, 'changed-later');

        self::assertSame($first, RequestId::of($request));
        self::assertSame($first, $request->attributes->get(RequestId::ATTRIBUTE));
    }

    public function testGeneratedIdsDiffer(): void
    {
        self::assertNotSame(RequestId::generate(), RequestId::generate());
    }
}
