<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image\Legacy;

use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\ProxyCdn\Image\WebpConversion;
use Mfonte\ProxyCdn\Image\WebpConverter;
use Psr\Log\LoggerInterface;

/**
 * Interim port of the 2.x "api" conversion: posts the cached image to the
 * 1.x conversion API (multipart "images[0]" plus a "descriptors" JSON
 * field, key in the "-x-api-key" header) and writes the returned WEBP
 * atomically next to it.
 *
 * As in 2.x the API certificate is not verified, redirects are followed and
 * there is no timeout.
 *
 * @internal replaced by RemoteConverter in PRX-8; do not build on it
 */
final class ConversionApiConverter implements WebpConverter
{
    private const USER_AGENT = 'PHP cUrl connector for MWEBP';

    /**
     * @var string|null
     */
    private $apiServer;

    /**
     * @var string|null
     */
    private $apiKey;

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param string|null $apiServer URL of the conversion endpoint (WEBP_API_SERVER)
     * @param string|null $apiKey    API key (WEBP_API_KEY)
     */
    public function __construct(?string $apiServer, ?string $apiKey, CacheStorage $storage, LoggerInterface $logger)
    {
        $this->apiServer = $apiServer;
        $this->apiKey = $apiKey;
        $this->storage = $storage;
        $this->logger = $logger;
    }

    public function convert(string $sourceFile, string $targetFile): WebpConversion
    {
        if (!is_file($sourceFile)) {
            return WebpConversion::failed('Source file does not exist');
        }
        if ($this->apiServer === null || $this->apiKey === null) {
            return WebpConversion::failed('WEBP_API_SERVER or WEBP_API_KEY not defined');
        }

        $result = $this->post($this->apiServer, $this->apiKey, $sourceFile);
        if (is_string($result)) {
            $this->logger->error('webp api failed', ['source' => $sourceFile, 'err' => $result]);

            return WebpConversion::failed($result);
        }

        $first = $result[0] ?? null;
        if (!is_array($first) || !isset($first['webp_image_base64']) || !is_string($first['webp_image_base64'])) {
            return WebpConversion::failed('Invalid response from API');
        }

        $webp = base64_decode($first['webp_image_base64'], true);
        if ($webp === false) {
            return WebpConversion::failed('Base64 decode failed');
        }

        if (!$this->storage->write($targetFile, $webp)) {
            return WebpConversion::failed('Atomic write failed');
        }

        return WebpConversion::converted($targetFile);
    }

    /**
     * Posts $sourceFile to the API.
     *
     * @return array<mixed>|string the "response" list of the API, or the error message
     */
    private function post(string $apiServer, string $apiKey, string $sourceFile)
    {
        $basename = basename($sourceFile);
        $realPath = realpath($sourceFile);
        $mime = function_exists('mime_content_type') ? mime_content_type($sourceFile) : false;
        $fields = [
            'images[0]' => curl_file_create(
                $realPath === false ? $sourceFile : $realPath,
                $mime === false ? 'application/octet-stream' : $mime,
                $basename
            ),
            'descriptors' => (string) json_encode([['file_id' => $basename, 'filename' => $basename]]),
        ];

        $handle = curl_init($apiServer);
        if ($handle === false) {
            return 'Unknown error with HTTP_RESPONSE_CODE="0"';
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['-x-api-key: ' . $apiKey, 'User-Agent: ' . self::USER_AGENT],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (is_array($decoded) && $status === 200 && array_key_exists('response', $decoded)) {
            return is_array($decoded['response']) ? $decoded['response'] : [];
        }
        if (is_array($decoded) && array_key_exists('message', $decoded) && is_scalar($decoded['message'])) {
            return (string) $decoded['message'];
        }

        return 'Unknown error with HTTP_RESPONSE_CODE="' . $status . '"';
    }
}
