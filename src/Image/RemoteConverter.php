<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Origin\CircuitBreaker;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Psr\Log\LoggerInterface;

/**
 * The client of the conversion API v2 (finding P-6). This step discovers
 * what the API can do, from its GET /healthz.
 *
 * Capabilities: /healthz is asked with the key, which makes the API add
 * its Capabilities (the library's schema) to the answer; they are kept
 * for CapabilityCache::SUCCESS_TTL seconds. An answer that cannot be read,
 * or that Capabilities::fromArray() refuses, means that remote conversion
 * is unavailable (getCapabilities() returns null), for $unavailableTtl
 * seconds; it never escapes as an exception.
 *
 * Answers other than 200 (ApiClient never follows a redirect) map to a
 * ConversionFailure and update the breaker:
 *
 * | Answer                                        | Failure     | Breaker  |
 * | --------------------------------------------- | ----------- | -------- |
 * | 503 concurrency_unavailable,                  | unavailable | opened   |
 * | rate_limit_unavailable,                       |             | at once  |
 * | authentication_not_configured; 401, 403       |             |          |
 * | other 503, 429, 5xx but conversion_failed and | unavailable | counted  |
 * | output_verification_failed; no answer (DNS,   |             |          |
 * | connect, TLS, timeout); a redirect            |             |          |
 * | 400, 413, 415, 422, conversion_failed,        | rejected    | closed   |
 * | output_verification_failed                    |             |          |
 *
 * "Opened at once" uses a second breaker of threshold 1 on the same
 * state: the API itself says it cannot take conversions for a while.
 * The error code of an answer is read from its JSON body
 * ({"error": {"code", "message"}}). The key never appears in a message or
 * a log entry.
 */
final class RemoteConverter
{
    public const NAME = 'remote';

    /**
     * The largest JSON body read: /healthz and the error answers.
     */
    public const MAX_JSON_BYTES = 65536;

    /**
     * Seconds the /healthz call may take at most: the first one after the
     * API starts probes its backends and its isolation, which takes
     * seconds (the API caches the result).
     */
    public const DISCOVERY_TIMEOUT = 30;

    /**
     * The API's error codes after which it takes no conversion for a
     * while: the breaker opens at once.
     */
    private const TRIPPING_CODES = ['concurrency_unavailable', 'rate_limit_unavailable', 'authentication_not_configured', 'missing_api_key', 'invalid_api_key', 'ip_not_allowed', 'https_required'];

    /**
     * The 5xx error codes that are about the image, not the API.
     */
    private const IMAGE_SERVER_CODES = [ErrorCode::CONVERSION_FAILED, ErrorCode::OUTPUT_VERIFICATION_FAILED];

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var ApiClient
     */
    private $client;

    /**
     * @var CircuitBreaker
     */
    private $breaker;

    /**
     * @var CircuitBreaker
     */
    private $trip;

    /**
     * @var CapabilityCache
     */
    private $capabilityCache;

    /**
     * @var int
     */
    private $unavailableTtl;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * The capabilities read during this request: false until read.
     *
     * @var Capabilities|false|null
     */
    private $capabilities = false;

    /**
     * @param string         $baseUrl        IMAGE_API_URL, without trailing slash
     * @param string         $apiKey         IMAGE_API_KEY
     * @param CircuitBreaker $breaker        the breaker of the API, with CIRCUIT_BREAKER_THRESHOLD
     * @param CircuitBreaker $trip           the same breaker state with threshold 1, which opens at once
     * @param int            $unavailableTtl seconds a failed discovery is remembered (NETWORK_ERROR_TTL)
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        ApiClient $client,
        CircuitBreaker $breaker,
        CircuitBreaker $trip,
        CapabilityCache $capabilityCache,
        int $unavailableTtl,
        LoggerInterface $logger
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->client = $client;
        $this->breaker = $breaker;
        $this->trip = $trip;
        $this->capabilityCache = $capabilityCache;
        $this->unavailableTtl = $unavailableTtl;
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getCapabilities(): ?Capabilities
    {
        if ($this->capabilities !== false) {
            return $this->capabilities;
        }
        $now = time();
        $cached = $this->capabilityCache->load($now);
        if ($cached !== null) {
            return $this->capabilities = $cached === false ? null : $cached;
        }

        $this->capabilities = $this->discover();
        if ($this->capabilities === null) {
            $this->capabilityCache->storeUnavailable($now, $this->unavailableTtl);
        } else {
            $this->capabilityCache->storeCapabilities($this->capabilities, $now);
        }

        return $this->capabilities;
    }

    /**
     * The failure of an answer other than 200, with the breaker updated.
     */
    private function failureOf(ApiResponse $response): ConversionFailure
    {
        $status = $response->getStatus();
        list($code, $message) = self::errorOf($response->getBody());
        $reason = sprintf('The conversion API answered %d%s', $status, $message === '' ? '' : ': ' . $message);
        if ($status >= 300 && $status < 400) {
            $this->breaker->recordFailure($this->breakerKey());

            return ConversionFailure::unavailable(self::NAME, 'The conversion API answered a redirect, which is not followed (check IMAGE_API_URL)', 'redirected', $status);
        }
        if (in_array($code, self::TRIPPING_CODES, true) || $status === 401 || $status === 403) {
            $this->trip->recordFailure($this->breakerKey());

            return ConversionFailure::unavailable(self::NAME, $reason, $code ?? 'refused', $status);
        }
        if ($status === 429 || $status >= 500 && !in_array($code, self::IMAGE_SERVER_CODES, true)) {
            $this->breaker->recordFailure($this->breakerKey());

            return ConversionFailure::unavailable(self::NAME, $reason, $code ?? 'server_error', $status);
        }

        return ConversionFailure::rejected(self::NAME, $reason, $code ?? 'client_error', $status);
    }

    /**
     * Asks /healthz for the capabilities of the API; null when they cannot
     * be had, with the reason logged.
     */
    private function discover(): ?Capabilities
    {
        $response = $this->client->get(
            $this->baseUrl . '/healthz',
            ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'],
            self::MAX_JSON_BYTES,
            (float) self::DISCOVERY_TIMEOUT
        );
        $context = ['api' => $this->baseUrl, 'status' => $response->getStatus()];
        if ($response->getTransportErrorCode() !== 0 || $response->getStatus() === 0) {
            $this->breaker->recordFailure($this->breakerKey());
            $this->logger->warning('conversion API capabilities unavailable', $context + [
                ConversionFailure::LOG_ERROR_CODE => $response->isTooLarge() ? 'response_too_large' : 'unreachable',
                'reason' => self::printable($response->getTransportError()),
            ]);

            return null;
        }
        if ($response->getStatus() !== 200) {
            $failure = $this->failureOf($response);
            $this->logger->warning('conversion API capabilities unavailable', $context + $failure->logContext());

            return null;
        }

        $document = json_decode($response->getBody(), true);
        $data = is_array($document) && ($document['status'] ?? null) === 'ok' ? ($document['capabilities'] ?? null) : null;
        if (!is_array($data)) {
            $this->logger->error('conversion API capabilities unreadable', $context + [
                ConversionFailure::LOG_ERROR_CODE => 'invalid_capabilities',
                'reason' => 'The /healthz answer holds no capabilities: is IMAGE_API_KEY accepted, and is the API at version 2?',
            ]);

            return null;
        }
        try {
            return Capabilities::fromArray($data);
        } catch (LibraryException $exception) {
            $this->logger->error('conversion API capabilities unreadable', $context + [
                ConversionFailure::LOG_ERROR_CODE => 'invalid_capabilities',
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The error code and message of an error body of the API
     * ({"error": {"code", "message"}}): the code when it is a code (lower
     * case letters and "_"), the message cut to printable ASCII.
     *
     * @return array{string|null, string}
     */
    private static function errorOf(string $body): array
    {
        $document = json_decode($body, true);
        $error = is_array($document) && is_array($document['error'] ?? null) ? $document['error'] : [];
        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;

        return [
            is_string($code) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $code) === 1 ? $code : null,
            is_string($message) ? self::printable($message) : '',
        ];
    }

    /**
     * $text with every character but printable ASCII removed, cut to 200
     * characters: safe in a log line.
     */
    private static function printable(string $text): string
    {
        return substr((string) preg_replace('/[^\x20-\x7E]/', '', $text), 0, 200);
    }

    private function breakerKey(): string
    {
        return $this->baseUrl;
    }
}
