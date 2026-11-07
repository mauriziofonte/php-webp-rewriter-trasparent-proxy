<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Http;

/**
 * A self-signed TLS certificate for 127.0.0.1 and localhost, generated with
 * ext-openssl for the tests that talk to a TLS server.
 */
final class TestCertificate
{
    /**
     * @var string
     */
    private $serverPem;

    /**
     * @var string
     */
    private $caPem;

    private function __construct(string $serverPem, string $caPem)
    {
        $this->serverPem = $serverPem;
        $this->caPem = $caPem;
    }

    /**
     * Whether certificates can be generated: ext-openssl is loaded.
     */
    public static function isSupported(): bool
    {
        return extension_loaded('openssl');
    }

    /**
     * Generates a key and a certificate valid for one day into $directory:
     * server.pem (certificate and key, for the server) and ca.pem (the
     * certificate alone, to trust it).
     *
     * @throws \RuntimeException when OpenSSL fails
     */
    public static function create(string $directory): self
    {
        $config = $directory . '/openssl.cnf';
        file_put_contents($config, implode("\n", [
            '[req]',
            'distinguished_name = dn',
            'x509_extensions = v3',
            'prompt = no',
            '[dn]',
            'CN = localhost',
            '[v3]',
            'subjectAltName = DNS:localhost, IP:127.0.0.1',
            'basicConstraints = critical, CA:TRUE',
            'keyUsage = critical, digitalSignature, keyEncipherment, keyCertSign',
            'extendedKeyUsage = serverAuth',
            '',
        ]));
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3'];

        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new \RuntimeException('Cannot generate a key: ' . (string) openssl_error_string());
        }
        // openssl_csr_new() takes the key by reference: give it a copy.
        $csrKey = $key;
        $csr = openssl_csr_new(['commonName' => 'localhost'], $csrKey, $options);
        if (is_bool($csr)) {
            throw new \RuntimeException('Cannot create a CSR: ' . (string) openssl_error_string());
        }
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options, random_int(1, PHP_INT_MAX));
        $serverPem = $directory . '/server.pem';
        $caPem = $directory . '/ca.pem';
        $keyPem = $directory . '/key.pem';
        if ($certificate === false || !openssl_x509_export_to_file($certificate, $caPem) || !openssl_pkey_export_to_file($key, $keyPem, null, $options)) {
            throw new \RuntimeException('Cannot sign the certificate: ' . (string) openssl_error_string());
        }
        file_put_contents($serverPem, (string) file_get_contents($caPem) . (string) file_get_contents($keyPem));

        return new self($serverPem, $caPem);
    }

    /**
     * The PEM file with the certificate and its key.
     */
    public function getServerPem(): string
    {
        return $this->serverPem;
    }

    /**
     * The PEM file with the certificate alone.
     */
    public function getCaPem(): string
    {
        return $this->caPem;
    }
}
