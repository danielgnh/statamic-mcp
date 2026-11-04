<?php

namespace Danielgnh\StatamicMcp\Support;

use Carbon\CarbonInterface;
use Closure;
use Danielgnh\StatamicMcp\Tools\ToolException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\IpUtils;

class SourceDownloader
{
    private const int MAX_REDIRECTS = 3;

    private const int TIMEOUT_SECONDS = 15;

    private const float MIN_HOP_TIMEOUT_SECONDS = 0.1;

    private const array NON_PUBLIC_RANGES_PHP_FLAGS_MISS = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
    ];

    private const string AZURE_WIRE_SERVER = '168.63.129.16';

    private const string IPV6_GLOBAL_UNICAST = '2000::/3';

    private const string NAT64_PREFIX = '64:ff9b::/96';

    /**
     * @param  null|Closure(string): list<string>  $resolver
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /**
     * @return array{0: string, 1: string}
     */
    public function download(string $url): array
    {
        $maxBytes = self::maxKilobytes() * 1024;
        $deadline = now()->addSeconds(self::TIMEOUT_SECONDS);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ip = $this->validated($url);

            $response = $this->fetch($url, $ip, $maxBytes, $deadline);

            if ($response->redirect()) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new ToolException('source_url redirected without a Location header — nothing was uploaded');
                }

                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }

            if (! $response->successful()) {
                throw new ToolException(sprintf('source_url responded with HTTP %d — nothing was uploaded', $response->status()));
            }

            $body = $response->body();

            if (strlen($body) > $maxBytes) {
                throw $this->sizeLimitException();
            }

            if ($body === '') {
                throw new ToolException('source_url returned an empty body — nothing was uploaded');
            }

            return [$body, rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)))];
        }

        throw new ToolException(sprintf('source_url redirected more than %d times — aborted', self::MAX_REDIRECTS));
    }

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
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        if ($this->resolver instanceof Closure) {
            return ($this->resolver)($host);
        }

        return array_values(array_filter(array_merge(
            array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        )));
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (IpUtils::checkIp($ip, [...self::NON_PUBLIC_RANGES_PHP_FLAGS_MISS, self::AZURE_WIRE_SERVER])) {
            return false;
        }

        if (IpUtils::checkIp($ip, self::NAT64_PREFIX)) {
            return $this->isPublicIp((string) inet_ntop(substr((string) inet_pton($ip), -4)));
        }

        return ! str_contains($ip, ':') || IpUtils::checkIp($ip, self::IPV6_GLOBAL_UNICAST);
    }

    private function fetch(string $url, string $ip, int $maxBytes, CarbonInterface $deadline): Response
    {
        $timeoutSeconds = $this->ensureTimeLeft($deadline);

        $port = parse_url($url, PHP_URL_PORT)
            ?? (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);

        try {
            return Http::setHandler(new CurlHandler)->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_CONNECT_TO => [
                    sprintf('::%s:%d', str_contains($ip, ':') ? "[{$ip}]" : $ip, $port),
                ]],
                'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw $this->sizeLimitException();
                    }
                },
                'progress' => function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                    if ($downloaded > $maxBytes) {
                        throw $this->sizeLimitException();
                    }
                },
            ])->timeout($timeoutSeconds)->get($url);
        } catch (HttpClientException $e) {
            $onHeadersFailure = $e->getPrevious()?->getPrevious();

            if ($onHeadersFailure instanceof ToolException) {
                throw $onHeadersFailure;
            }

            $this->ensureTimeLeft($deadline);

            throw new ToolException(sprintf('could not download source_url: %s', $e->getMessage()), $e->getCode(), $e);
        }
    }

    private function ensureTimeLeft(CarbonInterface $deadline): float
    {
        $secondsLeft = now()->diffInSeconds($deadline);

        if ($secondsLeft < self::MIN_HOP_TIMEOUT_SECONDS) {
            throw new ToolException(sprintf('source_url took longer than %d seconds — aborted', self::TIMEOUT_SECONDS));
        }

        return $secondsLeft;
    }

    private function sizeLimitException(): ToolException
    {
        return new ToolException(sprintf('source_url file exceeds the %d KB limit (statamic.mcp.uploads.max_size)', self::maxKilobytes()));
    }

    public static function maxKilobytes(): int
    {
        return (int) config('statamic.mcp.uploads.max_size', 10240);
    }
}
