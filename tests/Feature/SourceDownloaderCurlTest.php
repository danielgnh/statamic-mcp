<?php

use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

function fetchOverRealCurl(string $path): Response
{
    $listener = @stream_socket_server('tcp://127.0.0.1:0');

    if ($listener === false) {
        test()->markTestSkipped('this environment cannot bind a loopback port');
    }

    $port = (int) parse_url('tcp://'.stream_socket_get_name($listener, false), PHP_URL_PORT);
    fclose($listener);

    $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../__fixtures__/source-server.php']);
    $server->start();

    try {
        retry(50, function () use ($port) {
            fclose(fsockopen('127.0.0.1', $port, timeout: 0.1));
        }, 100);

        Http::globalOptions(['proxy' => '']);

        return (new ReflectionMethod(SourceDownloader::class, 'fetch'))
            ->invoke(new SourceDownloader, "http://not-resolvable.invalid:{$port}{$path}", '127.0.0.1', 1024, now()->addSeconds(5));
    } finally {
        $server->stop(0);
    }
}

it('connects to the validated address instead of asking DNS again, so a rebinding host cannot swap it', function () {
    expect(fetchOverRealCurl('/pinned')->body())->toBe('PINNED');
});

it('stops at a Content-Length over the cap before reading the body', function () {
    expect(fn () => fetchOverRealCurl('/announces-too-much'))
        ->toThrow(ToolException::class, 'exceeds the 10240 KB limit');
});

it('stops a body that grows past the cap, because Content-Length is advisory', function () {
    expect(fn () => fetchOverRealCurl('/grows-past-the-cap'))
        ->toThrow(ToolException::class, 'exceeds the 10240 KB limit');
});

it('answers a refused connection with a tool error', function () {
    Http::globalOptions(['proxy' => '']);

    expect(fn () => (new ReflectionMethod(SourceDownloader::class, 'fetch'))
        ->invoke(new SourceDownloader, 'http://not-resolvable.invalid:1/', '127.0.0.1', 1024, now()->addSeconds(5)))
        ->toThrow(ToolException::class, 'could not download source_url');
});

it('answers an error status with a tool error even when its Content-Length is over the cap', function () {
    expect(fn () => fetchOverRealCurl('/not-found-announcing-too-much'))
        ->toThrow(ToolException::class, 'could not download source_url: HTTP request returned status code 404');
});

it('refuses a URL upload where curl_exec is disabled, because no other handler honors the pinned address', function () {
    $download = new Process([PHP_BINARY, '-d', 'disable_functions=curl_exec,curl_multi_exec', __DIR__.'/../__fixtures__/download-without-curl.php']);

    expect($download->mustRun()->getOutput())->toContain('source_url downloads need curl_exec');
});
