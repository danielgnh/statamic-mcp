<?php

use Danielgnh\StatamicMcp\Setup\AuthGuardEditor;
use Danielgnh\StatamicMcp\Setup\EditResult;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'mcp-auth-');
});

afterEach(function () {
    @unlink($this->path);
});

function standardAuthConfig(): string
{
    return <<<'PHP'
<?php

return [

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

];
PHP;
}

it('inserts a passport api guard into a standard auth config', function () {
    file_put_contents($this->path, standardAuthConfig());

    $result = (new AuthGuardEditor)->apply($this->path);
    $contents = file_get_contents($this->path);

    expect($result)->toBe(EditResult::Applied)
        ->and($contents)->toContain("'api' => [")
        ->and($contents)->toContain("'driver' => 'passport'")
        // The web guard is untouched:
        ->and($contents)->toContain("'driver' => 'session'");

    // The edited file must still be valid PHP returning the expected shape.
    $config = eval('?>'.$contents);

    expect($config['guards']['api'])->toBe(['driver' => 'passport', 'provider' => 'users'])
        ->and($config['guards']['web']['driver'])->toBe('session');
});

it('rewrites the driver of an existing non-passport api guard', function () {
    $withApiGuard = str_replace(
        "'guards' => [\n",
        "'guards' => [\n        'api' => [\n            'driver' => 'token',\n            'provider' => 'users',\n        ],\n",
        standardAuthConfig()
    );
    file_put_contents($this->path, $withApiGuard);

    $result = (new AuthGuardEditor)->apply($this->path);
    $config = eval('?>'.file_get_contents($this->path));

    expect($result)->toBe(EditResult::Applied)
        ->and($config['guards']['api']['driver'])->toBe('passport')
        ->and($config['guards']['web']['driver'])->toBe('session');
});

it('skips when the api guard already uses passport', function () {
    $ready = str_replace(
        "'guards' => [\n",
        "'guards' => [\n        'api' => [\n            'driver' => 'passport',\n            'provider' => 'users',\n        ],\n",
        standardAuthConfig()
    );
    file_put_contents($this->path, $ready);

    expect((new AuthGuardEditor)->apply($this->path))->toBe(EditResult::Skipped)
        ->and(file_get_contents($this->path))->toBe($ready);
});

it('bails on a file without a guards anchor, leaving it untouched', function () {
    $weird = "<?php\n\nreturn array_merge(\$base, ['guards' => \$guards]);\n";
    file_put_contents($this->path, $weird);

    expect((new AuthGuardEditor)->apply($this->path))->toBe(EditResult::Bailed)
        ->and(file_get_contents($this->path))->toBe($weird);
});
