<?php

namespace Danielgnh\StatamicMcp\Tests;

use Closure;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

trait AutoloadsServerWithMissingParent
{
    private Closure $serverAutoloader;

    #[Before]
    protected function registerServerAutoloader(): void
    {
        $this->serverAutoloader = function (string $class): void {
            if ($class === 'ServerWithMissingParent') {
                require __DIR__.'/__fixtures__/ServerWithMissingParent.php';
            }
        };

        spl_autoload_register($this->serverAutoloader);
    }

    #[After]
    protected function unregisterServerAutoloader(): void
    {
        spl_autoload_unregister($this->serverAutoloader);
    }
}
