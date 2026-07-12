<?php

use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\UsersRepositoryEditor;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'mcp-users-');
});

afterEach(function () {
    @unlink($this->path);
});

it('flips the repository to eloquent', function () {
    file_put_contents($this->path, <<<'PHP'
<?php

return [

    'repository' => 'file',

    'repositories' => [
        'file' => [
            'driver' => 'file',
        ],
    ],

];
PHP);

    $result = (new UsersRepositoryEditor)->apply($this->path);

    expect($result)->toBe(EditResult::Applied)
        ->and(file_get_contents($this->path))->toContain("'repository' => 'eloquent'")
        // Only the top-level assignment changes — the repositories map keeps its keys:
        ->and(file_get_contents($this->path))->toContain("'file' => [");
});

it('skips when already eloquent', function () {
    file_put_contents($this->path, "<?php\n\nreturn [\n    'repository' => 'eloquent',\n];\n");

    expect((new UsersRepositoryEditor)->apply($this->path))->toBe(EditResult::Skipped);
});

it('ignores commented-out repository lines', function () {
    file_put_contents($this->path, <<<'PHP'
<?php

return [

    // 'repository' => 'eloquent',
    'repository' => 'file',

];
PHP);

    $result = (new UsersRepositoryEditor)->apply($this->path);
    $contents = file_get_contents($this->path);

    expect($result)->toBe(EditResult::Applied)
        ->and($contents)->toContain("// 'repository' => 'eloquent',")
        ->and($contents)->toContain("    'repository' => 'eloquent',");
});

it('bails on a file without the expected anchor, leaving it untouched', function () {
    $weird = "<?php\n\nreturn [\n    'repository' => env('USERS_REPO'),\n];\n";
    file_put_contents($this->path, $weird);

    expect((new UsersRepositoryEditor)->apply($this->path))->toBe(EditResult::Bailed)
        ->and(file_get_contents($this->path))->toBe($weird);
});

it('bails when the file does not exist', function () {
    expect((new UsersRepositoryEditor)->apply('/nonexistent/users.php'))->toBe(EditResult::Bailed);
});
