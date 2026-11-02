<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Observability\HealthCheck;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operations layer around ProxyKernel, which the front controller
 * runs (a host application may mount either):
 *
 * - every response carries the request ID in X-Request-Id (RequestId: the
 *   client's when it is valid, otherwise a new one), the ID of the log
 *   entries of the request;
 * - GET /healthz answers {"status":"ok"} (200) or {"status":"unavailable",
 *   "checks":{...}} (503) for a load balancer, on any Host header
 *   (HealthCheck);
 * - every other request is answered by ProxyKernel.
 *
 * /healthz never reveals a path, a version, a host name or a
 * configuration value. terminate() runs ProxyKernel::terminate().
 */
final class EdgeKernel
{
    public const HEALTH_PATH = '/healthz';

    /**
     * @var ProxyKernel
     */
    private $kernel;

    /**
     * @var HealthCheck
     */
    private $health;

    public function __construct(ProxyKernel $kernel, HealthCheck $health)
    {
        $this->kernel = $kernel;
        $this->health = $health;
    }

    public function handle(Request $request): Response
    {
        $requestId = RequestId::of($request);
        $method = $request->getRealMethod();
        $path = $request->getPathInfo();

        if ($path === self::HEALTH_PATH) {
            $response = $this->healthz($method);
        } else {
            $response = $this->kernel->handle($request);
        }

        $response->headers->set(RequestId::HEADER, $requestId);

        return $response;
    }

    /**
     * Runs the work left for after the response was sent: that of
     * ProxyKernel.
     */
    public function terminate(): void
    {
        $this->kernel->terminate();
    }

    /**
     * The answer to /healthz.
     */
    private function healthz(string $method): Response
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return self::methodNotAllowed();
        }
        $report = $this->health->check();
        $healthy = $report['status'] === HealthCheck::STATUS_OK;
        $document = $healthy ? ['status' => $report['status']] : $report;
        $body = (string) json_encode($document, JSON_UNESCAPED_SLASHES) . "\n";

        return self::withoutBodyForHead(new Response($body, $healthy ? 200 : 503, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]), $method);
    }

    private static function methodNotAllowed(): Response
    {
        return new Response("Method not allowed.\n", 405, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Allow' => 'GET, HEAD',
        ]);
    }

    /**
     * The response without its body for HEAD, its Content-Length kept.
     */
    private static function withoutBodyForHead(Response $response, string $method): Response
    {
        if ($method === 'HEAD') {
            $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
            $response->setContent('');
        }

        return $response;
    }
}
