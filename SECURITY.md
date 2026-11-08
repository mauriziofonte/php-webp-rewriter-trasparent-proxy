# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 3.x | Yes |
| 2.x and earlier | No. Upgrade to 3.x: see [UPGRADING.md](UPGRADING.md). |

Fixes are made on the latest 3.x release line.

## Reporting a vulnerability

Report vulnerabilities privately, through GitHub Security Advisories:

1. Open <https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy/security/advisories/new>.
2. Describe the problem, the affected version or commit, the configuration needed to reproduce it (without your real secrets), and its impact as you understand it.
3. Include a proof of concept when you have one: requests, configuration and the log lines of the request, with their `request_id`.

Do not open a public issue, pull request or discussion for a vulnerability, and do not disclose it publicly before a fix is available. The discussion and the coordination of a fix and of its disclosure take place in the advisory.

## Scope

In scope: the code of this repository, that is `src/`, `public/`, `bin/` and the web server and PHP configurations in `config/`, used as documented. For example: reading or writing a cache entry of another host, a path or query string that escapes the cache directory, a purge or resize URL accepted without a valid key, a request that makes the proxy contact an address it should not, or a header that a client can use to change what is stored for other clients.

Out of scope:

- Vulnerabilities of the dependencies (Symfony components, phpdotenv, the image library, PHP itself): report them to their maintainers. The image library and the conversion API have repositories of their own: [php-secure-image-converter](https://github.com/mauriziofonte/php-secure-image-converter) and [php-image-to-webp-conversion-api](https://github.com/mauriziofonte/php-image-to-webp-conversion-api).
- Configurations that the documentation warns against, such as session-dependent extensions in `CACHEABLE_EXTENSIONS`, `UPSTREAM_VERIFY_TLS=false` on an untrusted network, or a document root other than `public/`.
- The example keys and certificates of [`docker/cluster/`](docker/cluster/), which are placeholders for a local demonstration.
- The load a client can put on an origin by requesting distinct paths that exist there; see the security model in the [README](README.md#security-model) for the controls that bound it.
