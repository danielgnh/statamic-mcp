<?php

use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

Application::create();

try {
    (new SourceDownloader(fn () => ['93.184.216.34']))->download('http://example.test/a.png');
} catch (ToolException $e) {
    echo $e->getMessage();
}
