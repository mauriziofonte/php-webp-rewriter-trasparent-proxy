<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\MockTransport;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\MockTransport
 */
#[CoversClass(MockTransport::class)]
final class MockTransportTest extends TestCase
{
    private const URL = 'https://origin.test/a.css';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testARegisteredResponseHasItsBodyInATemporaryFile(): void
    {
        $mock = $this->mock();
        $mock->respond(self::URL, 200, ['Content-Type' => 'text/css'], 'body {}');

        $response = $mock->send(new HttpRequest(self::URL, ['Referer' => 'https://cdn.test']));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame(['content-type' => 'text/css', 'content-length' => '7'], $response->getHeaders());
        $body = $response->getBody();
        self::assertNotNull($body);
        self::assertSame('body {}', $body->getContents());
        self::assertSame($this->directory->path(), dirname($body->getPath()));
        self::assertCount(1, $mock->getRequests());
        self::assertSame('https://cdn.test', $mock->getRequests()[0]->getHeader('referer'));
    }

    public function testAnUnknownUrlIsNotFound(): void
    {
        $response = $this->mock()->send(new HttpRequest('https://origin.test/missing.css'));

        self::assertSame(HttpResponse::OUTCOME_NOT_FOUND, $response->getOutcome());
        self::assertNull($response->getBody());
    }

    public function testAFailure(): void
    {
        $mock = $this->mock();
        $mock->respond(self::URL, 200);
        $mock->fail(self::URL, HttpResponse::OUTCOME_TIMEOUT, 28, 'Operation timed out');

        $response = $mock->send(new HttpRequest(self::URL));

        self::assertSame(HttpResponse::OUTCOME_TIMEOUT, $response->getOutcome());
        self::assertSame(28, $response->getErrorCode());
        self::assertSame('Operation timed out', $response->getError());
    }

    public function testTheMaximumSizeApplies(): void
    {
        $mock = new MockTransport((new HttpClientOptions())->withMaxBodySize(3)->withTemporaryDirectory($this->directory->path()));
        $mock->respond(self::URL, 200, [], 'four');

        self::assertSame(HttpResponse::OUTCOME_TOO_LARGE, $mock->send(new HttpRequest(self::URL))->getOutcome());
        self::assertSame(['.', '..'], scandir($this->directory->path()));
    }

    public function testAHeadHasNoBody(): void
    {
        $mock = $this->mock();
        $mock->respond(self::URL, 200, [], 'body');

        $response = $mock->send(new HttpRequest(self::URL, [], HttpRequest::METHOD_HEAD));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertNull($response->getBody());
        self::assertSame('4', $response->getHeader('content-length'));
    }

    public function testConditionalRequestsAreAnswered304WhenTheValidatorsMatch(): void
    {
        $mock = $this->mock();
        $mock->respond(self::URL, 200, ['ETag' => '"v1"', 'Last-Modified' => 'Tue, 01 Sep 2026 10:00:00 GMT', 'Content-Type' => 'text/css'], 'body');
        $request = new HttpRequest(self::URL);

        $byEtag = $mock->send($request->withConditions('W/"v1"', null));
        $byDate = $mock->send($request->withConditions(null, 'Wed, 02 Sep 2026 10:00:00 GMT'));
        $changed = $mock->send($request->withConditions('"v0"', null));
        $older = $mock->send($request->withConditions(null, 'Mon, 31 Aug 2026 10:00:00 GMT'));

        self::assertSame(HttpResponse::OUTCOME_NOT_MODIFIED, $byEtag->getOutcome());
        self::assertSame('"v1"', $byEtag->getHeader('etag'));
        self::assertNull($byEtag->getBody());
        self::assertSame(HttpResponse::OUTCOME_NOT_MODIFIED, $byDate->getOutcome());
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $changed->getOutcome());
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $older->getOutcome());
    }

    public function testRedirectsAreFollowedWhenEnabled(): void
    {
        $options = (new HttpClientOptions())->withTemporaryDirectory($this->directory->path());
        $following = new MockTransport($options->withRedirects(true, 1));
        $notFollowing = new MockTransport($options);
        foreach ([$following, $notFollowing] as $mock) {
            $mock->respond('https://origin.test/old.css', 301, ['Location' => '/a.css']);
            $mock->respond('https://origin.test/loop.css', 302, ['Location' => '/loop.css']);
            $mock->respond('https://origin.test/file.css', 302, ['Location' => 'file:///etc/passwd']);
            $mock->respond(self::URL, 200, [], 'final');
        }

        $followed = $following->send(new HttpRequest('https://origin.test/old.css'));
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $followed->getOutcome());
        self::assertSame(self::URL, $followed->getEffectiveUrl());
        self::assertSame(HttpResponse::OUTCOME_REDIRECT, $following->send(new HttpRequest('https://origin.test/loop.css'))->getOutcome());
        self::assertSame(HttpResponse::OUTCOME_OTHER, $following->send(new HttpRequest('https://origin.test/file.css'))->getOutcome());

        $kept = $notFollowing->send(new HttpRequest('https://origin.test/old.css'));
        self::assertSame(HttpResponse::OUTCOME_REDIRECT, $kept->getOutcome());
        self::assertSame('/a.css', $kept->getRedirectLocation());
    }

    private function mock(): MockTransport
    {
        return new MockTransport((new HttpClientOptions())->withTemporaryDirectory($this->directory->path()));
    }
}
