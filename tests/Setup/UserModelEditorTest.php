<?php

use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\UserModelEditor;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'mcp-model-');
});

afterEach(function () {
    @unlink($this->path);
});

function standardUserModel(): string
{
    return <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];
}
PHP;
}

it('adds the trait and interface to a standard user model', function () {
    file_put_contents($this->path, standardUserModel());

    $result = (new UserModelEditor)->apply($this->path, 'Laravel\Passport\Contracts\OAuthenticatable');
    $contents = file_get_contents($this->path);

    expect($result)->toBe(EditResult::Applied)
        ->and($contents)->toContain('use Laravel\Passport\HasApiTokens;')
        ->and($contents)->toContain('use Laravel\Passport\Contracts\OAuthenticatable;')
        ->and($contents)->toContain('class User extends Authenticatable implements OAuthenticatable')
        ->and($contents)->toContain("{\n    use HasApiTokens;")
        // Existing members survive:
        ->and($contents)->toContain('use Notifiable;')
        ->and($contents)->toContain('protected $fillable');
});

it('adds only the trait when no interface is available', function () {
    file_put_contents($this->path, standardUserModel());

    $result = (new UserModelEditor)->apply($this->path, null);
    $contents = file_get_contents($this->path);

    expect($result)->toBe(EditResult::Applied)
        ->and($contents)->toContain('use Laravel\Passport\HasApiTokens;')
        ->and($contents)->toContain("class User extends Authenticatable\n")
        ->and($contents)->not->toContain('implements');
});

it('extends an existing implements clause instead of adding a second one', function () {
    file_put_contents($this->path, str_replace(
        'class User extends Authenticatable',
        'class User extends Authenticatable implements MustVerifyEmail',
        standardUserModel()
    ));

    (new UserModelEditor)->apply($this->path, 'Laravel\Passport\Contracts\OAuthenticatable');

    expect(file_get_contents($this->path))
        ->toContain('class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable');
});

it('skips when the passport trait is already present', function () {
    file_put_contents($this->path, str_replace(
        'use Illuminate\Notifications\Notifiable;',
        "use Illuminate\Notifications\Notifiable;\nuse Laravel\Passport\HasApiTokens;",
        standardUserModel()
    ));

    expect((new UserModelEditor)->apply($this->path, null))->toBe(EditResult::Skipped);
});

it('bails when a different HasApiTokens (e.g. Sanctum) is in play', function () {
    $sanctum = str_replace(
        'use Illuminate\Notifications\Notifiable;',
        "use Illuminate\Notifications\Notifiable;\nuse Laravel\Sanctum\HasApiTokens;",
        standardUserModel()
    );
    file_put_contents($this->path, $sanctum);

    expect((new UserModelEditor)->apply($this->path, null))->toBe(EditResult::Bailed)
        ->and(file_get_contents($this->path))->toBe($sanctum);
});

it('bails on a model without a recognizable class declaration', function () {
    $weird = "<?php\n\nnamespace App\Models;\n\nclass User extends Authenticatable implements\n    MustVerifyEmail\n{\n}\n";
    file_put_contents($this->path, $weird);

    expect((new UserModelEditor)->apply($this->path, null))->toBe(EditResult::Bailed)
        ->and(file_get_contents($this->path))->toBe($weird);
});
