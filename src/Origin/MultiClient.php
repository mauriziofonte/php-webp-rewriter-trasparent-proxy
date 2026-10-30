<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

use Mfonte\ProxyCdn\Exception\InvalidArgumentException;

/**
 * Sends several requests at once on curl_multi, with the options of a
 * CurlTransport: each response is the one CurlTransport::send() would give.
 *
 * At most $concurrency transfers run at a time; the others wait in order.
 * The multi handle and the easy handles are kept for the next sendAll(), so
 * connections are reused, and with libcurl 7.74 and later HTTP/2 transfers
 * to one origin share a connection (CURLOPT_PIPEWAIT, see
 * CurlTransport::waitsForMultiplexingSafely()).
 */
final class MultiClient
{
    /**
     * @var CurlTransport
     */
    private $transport;

    /**
     * @var int
     */
    private $concurrency;

    /**
     * @var CurlMultiHandleOrResource|null
     */
    private $multi;

    /**
     * Easy handles of finished transfers, ready for the next ones.
     *
     * @var list<CurlHandleOrResource>
     */
    private $idle = [];

    /**
     * @param int $concurrency the most transfers running at a time, at least 1
     *
     * @throws InvalidArgumentException when $concurrency is below 1
     */
    public function __construct(CurlTransport $transport, int $concurrency = 8)
    {
        if ($concurrency < 1) {
            throw new InvalidArgumentException('The concurrency must be at least 1, got ' . $concurrency);
        }
        $this->transport = $transport;
        $this->concurrency = $concurrency;
    }

    /**
     * Whether ext-curl is usable, curl_multi included.
     */
    public static function isAvailable(): bool
    {
        return CurlTransport::isAvailable()
            && function_exists('curl_multi_init')
            && function_exists('curl_multi_exec')
            && function_exists('curl_multi_select');
    }

    /**
     * Sends the requests and returns their responses with the same keys, in
     * the same order. A failed transfer is a response with its failure
     * outcome; this method does not throw.
     *
     * @template K of array-key
     *
     * @param array<K, HttpRequest> $requests
     *
     * @return array<K, HttpResponse>
     */
    public function sendAll(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        if ($this->multi === null) {
            $this->multi = curl_multi_init();
        }
        $multi = $this->multi;

        $keys = array_keys($requests);
        $count = count($keys);
        $next = 0;
        /** @var array<int, array{0: K, 1: CurlHandleOrResource, 2: ResponseCollector}> $running */
        $running = [];
        /** @var array<K, HttpResponse> $responses */
        $responses = [];

        while ($next < $count || $running !== []) {
            while ($next < $count && count($running) < $this->concurrency) {
                $key = $keys[$next++];
                $handle = $this->idle !== [] ? array_pop($this->idle) : $this->transport->createHandle();
                if ($handle === null) {
                    $responses[$key] = (new ResponseCollector($requests[$key], new HttpClientOptions(), true))
                        ->finish(HttpResponse::OUTCOME_OTHER, 0, 'The cURL handle cannot be created', $requests[$key]->getUrl(), 0.0);
                    continue;
                }
                $collector = $this->transport->prepare($handle, $requests[$key]);
                curl_multi_add_handle($multi, $handle);
                $running[self::id($handle)] = [$key, $handle, $collector];
            }

            do {
                $status = curl_multi_exec($multi, $active);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while (($message = curl_multi_info_read($multi)) !== false) {
                if ($message['msg'] !== CURLMSG_DONE) {
                    continue;
                }
                $id = self::id($message['handle']);
                if (!isset($running[$id])) {
                    continue;
                }
                $transfer = $running[$id];
                unset($running[$id]);
                curl_multi_remove_handle($multi, $transfer[1]);
                $errno = is_int($message['result']) ? $message['result'] : 0;
                $responses[$transfer[0]] = $this->transport->complete($transfer[1], $transfer[2], $errno);
                $this->idle[] = $transfer[1];
            }

            if ($status !== CURLM_OK) {
                $this->abandon($running, $responses, 'curl_multi_exec() failed: ' . (string) curl_multi_strerror($status));
                $running = [];
                $this->multi = null;
                continue;
            }
            if ($running !== [] && $active > 0 && curl_multi_select($multi, 1.0) === -1) {
                // No file descriptor to wait on yet (libcurl is resolving
                // or between retries): avoid spinning.
                usleep(1000);
            }
        }

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $responses[$key];
        }

        return $ordered;
    }

    /**
     * Ends the transfers left running after a failure of the multi handle.
     *
     * @template K of array-key
     *
     * @param array<int, array{0: K, 1: CurlHandleOrResource, 2: ResponseCollector}> $running
     * @param array<K, HttpResponse>                                                 $responses
     */
    private function abandon(array $running, array &$responses, string $error): void
    {
        foreach ($running as $transfer) {
            if ($this->multi !== null) {
                curl_multi_remove_handle($this->multi, $transfer[1]);
            }
            $responses[$transfer[0]] = $transfer[2]->finish(
                HttpResponse::OUTCOME_OTHER,
                0,
                $error,
                (string) curl_getinfo($transfer[1], CURLINFO_EFFECTIVE_URL),
                (float) curl_getinfo($transfer[1], CURLINFO_TOTAL_TIME)
            );
        }
    }

    /**
     * A key for an easy handle: a resource before PHP 8, an object since;
     * -1 for anything else.
     *
     * @param mixed $handle the handle curl_multi_info_read() reports
     */
    private static function id($handle): int
    {
        if (is_object($handle)) {
            return spl_object_id($handle);
        }

        return is_resource($handle) ? (int) $handle : -1;
    }
}
