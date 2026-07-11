<?php

namespace Danielgnh\StatamicMcp\Support;

use Closure;
use Danielgnh\StatamicMcp\Tools\ToolException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SSRF-guarded download of an agent-supplied source_url (spec §5) — the one
 * place this server makes outbound requests on model input. Fail-closed:
 * scheme, allowlist, and public-IP checks run per redirect hop, the
 * connection is pinned to the validated IP, and the byte cap is enforced
 * both mid-transfer and on the final body (Content-Length is advisory).
 */
class SourceDownloader
{
    private const MAX_REDIRECTS = 3;

    private const TIMEOUT_SECONDS = 15;

    /**
     * @param  null|Closure(string): list<string>  $resolver  host => IPs; injectable so tests never hit real DNS
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /**
     * @return array{0: string, 1: string} [binary contents, basename derived from the final URL ('' when it has none)]
     */
    public function download(string $url): array
    {
        $maxBytes = $this->maxKilobytes() * 1024;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ip = $this->validated($url);

            $response = $this->fetch($url, $ip, $maxBytes);

            if ($response->redirect()) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new ToolException('source_url redirected without a Location header — nothing was uploaded');
                }

                // Relative Location headers resolve against the current URL;
                // the next loop iteration re-runs every check on the result.
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }

            if (! $response->successful()) {
                throw new ToolException(sprintf('source_url responded with HTTP %d — nothing was uploaded', $response->status()));
            }

            $body = $response->body();

            if (strlen($body) > $maxBytes) {
                throw new ToolException(sprintf('source_url file exceeds the %d KB limit (statamic.mcp.uploads.max_size)', $this->maxKilobytes()));
            }

            if ($body === '') {
                throw new ToolException('source_url returned an empty body — nothing was uploaded');
            }

            return [$body, basename((string) parse_url($url, PHP_URL_PATH))];
        }

        throw new ToolException(sprintf('source_url redirected more than %d times — aborted', self::MAX_REDIRECTS));
    }

    /**
     * Scheme, allowlist, and DNS checks for one URL. Returns the IP the
     * connection must be pinned to.
     */
    private function validated(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw new ToolException(sprintf("source_url is not a valid absolute URL: '%s'", $url));
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new ToolException('source_url must use http or https');
        }

        $host = strtolower($parts['host']);

        $allowlist = config('statamic.mcp.uploads.source_allowlist');

        if (is_array($allowlist) && ! in_array($host, array_map(strtolower(...), $allowlist), true)) {
            throw new ToolException(sprintf(
                "host '%s' is not in the configured source allowlist (statamic.mcp.uploads.source_allowlist): %s",
                $host,
                implode(', ', $allowlist),
            ));
        }

        $ips = $this->resolve($host);

        if ($ips === []) {
            throw new ToolException(sprintf("could not resolve host '%s'", $host));
        }

        // EVERY resolved address must be public — a single private A record
        // on a multi-record host is an SSRF vector, not an edge case.
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new ToolException(sprintf(
                    "source_url host '%s' resolves to a private or reserved address — refusing to fetch",
                    $host,
                ));
            }
        }

        return $ips[0];
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        // Literal IPs (including bracketed IPv6) skip DNS entirely.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        return array_values(array_filter(array_merge(
            array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        )));
    }

    private function isPublicIp(string $ip): bool
    {
        // NO_PRIV_RANGE: 10/8, 172.16/12, 192.168/16, fc00::/7.
        // NO_RES_RANGE: 0/8, 127/8, 169.254/16, 240/4, ::1, ::, ::ffff:0:0/96, fe80::/10.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // 100.64.0.0/10 (carrier-grade NAT) is not covered by PHP's flags.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
                return false;
            }
        }

        return true;
    }

    private function fetch(string $url, string $ip, int $maxBytes): Response
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT)
            ?? (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);

        try {
            return Http::withOptions([
                // Hops are revalidated by download()'s loop, never by curl.
                'allow_redirects' => false,
                // Pin the connection to the validated IP — closes the DNS
                // rebinding window between check and use (spec §5). curl's
                // RESOLVE syntax is IPv4/IPv6-agnostic but pinning a
                // bracketed host is not; IPv6 literals were validated above.
                'curl' => str_contains($ip, ':') ? [] : [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                // Content-Length is advisory — abort mid-transfer at the cap.
                // (Guzzle wraps callback throws; unwrapped in the catch below.
                // Http::fake() never runs these — the body-length check in
                // download() is the layer tests exercise.)
                'on_headers' => function ($response) use ($maxBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new ToolException(sprintf('source_url file exceeds the %d KB limit (statamic.mcp.uploads.max_size)', $this->maxKilobytes()));
                    }
                },
                'progress' => function ($downloadTotal, int $downloaded) use ($maxBytes): void {
                    if ($downloaded > $maxBytes) {
                        throw new ToolException(sprintf('source_url file exceeds the %d KB limit (statamic.mcp.uploads.max_size)', $this->maxKilobytes()));
                    }
                },
            ])->timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (Throwable $e) {
            // Surface our own cap/guard exceptions from inside Guzzle wrappers.
            for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
                if ($previous instanceof ToolException) {
                    throw $previous;
                }
            }

            throw new ToolException(sprintf('could not download source_url: %s', $e->getMessage()));
        }
    }

    private function maxKilobytes(): int
    {
        return (int) config('statamic.mcp.uploads.max_size', 10240);
    }
}
