<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Observability\HealthCheck;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Observability\MetricsAuthenticator;
use Psr\Log\LoggerInterface;
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
 * - GET /metrics answers the Prometheus exposition (Metrics) to a request
 *   that carries one of METRICS_KEYS, 401 to any other; with no key
 *   configured the path is not served here;
 * - every other request is answered by ProxyKernel, then counted in
 *   Metrics by method, X-Cache-Status and status.
 *
 * Neither /healthz nor /metrics reveals a path, a version, a host name or a
 * configuration value. terminate() runs ProxyKernel::terminate() and writes
 * the counters of the request once the response is sent.
 */
final class EdgeKernel
{
    public const HEALTH_PATH = '/healthz';

    public const METRICS_PATH = '/metrics';

    /**
     * The realm of the WWW-Authenticate challenge of a refused scrape.
     */
    public const METRICS_REALM = 'proxy-cdn metrics';

    /**
     * The Content-Type of the text exposition format 0.0.4.
     */
    public const METRICS_CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * @var ProxyKernel
     */
    private $kernel;

    /**
     * @var HealthCheck
     */
    private $health;

    /**
     * @var Metrics
     */
    private $metrics;

    /**
     * @var MetricsAuthenticator
     */
    private $metricsAuthenticator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ProxyKernel $kernel,
        HealthCheck $health,
        Metrics $metrics,
        MetricsAuthenticator $metricsAuthenticator,
        LoggerInterface $logger
    ) {
        $this->kernel = $kernel;
        $this->health = $health;
        $this->metrics = $metrics;
        $this->metricsAuthenticator = $metricsAuthenticator;
        $this->logger = $logger;
    }

    public function handle(Request $request): Response
    {
        $started = microtime(true);
        $requestId = RequestId::of($request);
        $method = $request->getRealMethod();
        $path = $request->getPathInfo();

        if ($path === self::HEALTH_PATH) {
            $response = $this->healthz($method);
        } elseif ($path === self::METRICS_PATH && !$this->metricsAuthenticator->isDisabled()) {
            $response = $this->scrape($request, $method);
        } else {
            $response = $this->kernel->handle($request);
        }

        $response->headers->set(RequestId::HEADER, $requestId);
        $cacheStatus = $response->headers->get('X-Cache-Status');
        $this->metrics->recordRequest($method, is_string($cacheStatus) ? $cacheStatus : null, $response->getStatusCode(), microtime(true) - $started);

        return $response;
    }

    /**
     * Runs the work left for after the response was sent: that of
     * ProxyKernel, then the counters of the request are written.
     */
    public function terminate(): void
    {
        try {
            $this->kernel->terminate();
        } finally {
            if (!$this->metrics->flush()) {
                $this->logger->debug('metrics not recorded: the metrics store cannot be updated');
            }
        }
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

    /**
     * The answer to /metrics.
     */
    private function scrape(Request $request, string $method): Response
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return self::methodNotAllowed();
        }
        if (!$this->metricsAuthenticator->authenticate($request)) {
            $this->logger->warning('metrics scrape refused', ['client' => $request->server->get('REMOTE_ADDR')]);

            return new Response("A metrics key is required.\n", 401, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'WWW-Authenticate' => 'Bearer realm="' . self::METRICS_REALM . '"',
            ]);
        }

        return self::withoutBodyForHead(new Response($this->metrics->render(), 200, [
            'Content-Type' => self::METRICS_CONTENT_TYPE,
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
