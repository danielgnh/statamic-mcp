<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Addon\PlainMcpServer;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\Support\Skills\PricingPlaybook;
use Danielgnh\StatamicMcp\Tests\Support\Skills\SkillsServer;
use Danielgnh\StatamicMcp\Tests\Support\Skills\UnbuildableSkill;
use Danielgnh\StatamicMcp\Tests\Support\Skills\UnregistrableSkill;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

function doctorSkillsOutput(): string
{
    return preg_replace('/\s+/', ' ', Artisan::output());
}

/**
 * @param  array<string, string>  $files
 */
function doctorSkillFolder(string $root, array $files): string
{
    $path = $root.'/demo';

    foreach ($files as $name => $contents) {
        File::ensureDirectoryExists(dirname($path.'/'.$name));
        File::put($path.'/'.$name, $contents);
    }

    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

    return $path;
}

it('says nothing about skills on a site without any', function () {
    Artisan::call('statamic:mcp:doctor');

    expect(Artisan::output())->not->toContain('skill')->not->toContain('Skill');
});

it('lists the skills of the server class and says which ones its shouldRegister() hides in the console', function () {
    config(['statamic.mcp.server' => SkillsServer::class]);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(0)
        ->and(doctorSkillsOutput())
        ->toContain('[ OK ] 3 skill(s) valid.')
        ->toContain('Skill skill://landing-page/SKILL.md: '.SkillsServer::FIXTURES.'/landing-page')
        ->toContain('Skill skill://pricing-playbook/SKILL.md: '.PricingPlaybook::class.' (its shouldRegister() hides it here)');
});

it('marks the skills that add-ons registered', function () {
    Server::registerAddonSkills(SkillsServer::FIXTURES.'/seo-audit');

    Artisan::call('statamic:mcp:doctor');

    expect(doctorSkillsOutput())
        ->toContain('[ OK ] 1 skill(s) valid.')
        ->toContain('Add-on skill skill://seo-audit/SKILL.md: '.SkillsServer::FIXTURES.'/seo-audit');
});

it('warns naming a skill that laravel/mcp skips, with its reason', function (array $files, string $reason) {
    $folder = doctorSkillFolder(storage_path('framework/testing/skills'), $files);
    Server::registerAddonSkills($folder);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(0)
        ->and(doctorSkillsOutput())
        ->toContain("[WARN] Skill {$folder} is not served ({$reason}")
        ->not->toContain('skill(s) valid.');
})->with([
    'no frontmatter' => [['SKILL.md' => "# Demo\n"], 'The skill ['],
    'a name that is not the folder name' => [['SKILL.md' => "---\nname: other\ndescription: A demo.\n---\n"], 'The skill name [other] must match its directory'],
    'no SKILL.md' => [['notes.md' => 'Notes'], 'The skill file [SKILL.md] is not readable.'],
]);

it('warns about a skill folder that does not exist, such as a relative path from an add-on', function () {
    Server::registerAddonSkills('mcp-skills/never-there');

    Artisan::call('statamic:mcp:doctor');

    expect(doctorSkillsOutput())->toContain('[WARN] Skill mcp-skills/never-there is not served (The skill directory ['.resource_path('mcp-skills/never-there').'] does not exist.)');
});

it("says that the site's skill replaces an add-on skill of the same name, without a warning", function () {
    config(['statamic.mcp.server' => SkillsServer::class]);
    Server::registerAddonSkills(SkillsServer::FIXTURES.'/seo-audit');

    Artisan::call('statamic:mcp:doctor');

    expect(doctorSkillsOutput())
        ->toContain('[ OK ] 3 skill(s) valid.')
        ->toContain('Add-on skill '.SkillsServer::FIXTURES.'/seo-audit is replaced: '.SkillsServer::FIXTURES.'/shadow/seo-audit comes first and takes skill://seo-audit/SKILL.md.');
});

it('warns about the second of two add-on skills with one URI, because the first one registered is served', function () {
    Server::registerAddonSkills(SkillsServer::FIXTURES.'/seo-audit', SkillsServer::FIXTURES.'/shadow/seo-audit');

    Artisan::call('statamic:mcp:doctor');

    expect(doctorSkillsOutput())->toContain('[WARN] Skill '.SkillsServer::FIXTURES.'/shadow/seo-audit is not served: '.SkillsServer::FIXTURES.'/seo-audit comes first and takes skill://seo-audit/SKILL.md.');
});

it('warns that a skill behind a hidden one with the same URI reaches the users the first one refuses', function () {
    config(['statamic.mcp.server' => SkillsServer::class]);
    Server::registerAddonSkills(SkillsServer::FIXTURES.'/shadow/pricing-playbook');

    Artisan::call('statamic:mcp:doctor');

    expect(doctorSkillsOutput())->toContain('[WARN] Skill '.SkillsServer::FIXTURES.'/shadow/pricing-playbook takes skill://pricing-playbook/SKILL.md after '.PricingPlaybook::class.', so it is served to every user that one\'s shouldRegister() refuses.');
});

it('fails naming a skill class that cannot be built, because it breaks every skill and resource method', function () {
    Server::registerAddonSkills(UnbuildableSkill::class);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(1)
        ->and(doctorSkillsOutput())->toContain('[FAIL] Skill '.UnbuildableSkill::class.' cannot be built (missing API key)');
});

it('only warns about a shouldRegister() that throws, because one reading the request user throws only in the console', function () {
    Server::registerAddonSkills(UnregistrableSkill::class);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(0)
        ->and(doctorSkillsOutput())->toContain('[WARN] Skill '.UnregistrableSkill::class.': its shouldRegister() throws here (license server down)');
});

it('warns about a skill folder in the public directory, where anyone can download it', function () {
    $folder = doctorSkillFolder(public_path('mcp-skills'), ['SKILL.md' => "---\nname: demo\ndescription: A demo.\n---\n"]);
    Server::registerAddonSkills($folder);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(0)
        ->and(doctorSkillsOutput())->toContain("[WARN] Skill {$folder} lies in the public directory");
});

it('warns about a skill folder that a storage link publishes', function () {
    config(['filesystems.links' => [public_path('storage') => storage_path('app/public')]]);
    $folder = doctorSkillFolder(storage_path('app/public/mcp-skills'), ['SKILL.md' => "---\nname: demo\ndescription: A demo.\n---\n"]);
    Server::registerAddonSkills($folder);

    Artisan::call('statamic:mcp:doctor');

    expect(doctorSkillsOutput())->toContain("[WARN] Skill {$folder} lies in the public directory");
});

it('warns about a skill folder on the disk of an asset container, where an upload can rewrite it', function () {
    $container = Fixtures::assetContainer('images');
    $folder = doctorSkillFolder($container->diskPath().'/skills', ['SKILL.md' => "---\nname: demo\ndescription: A demo.\n---\n"]);
    Server::registerAddonSkills($folder);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(0)
        ->and(doctorSkillsOutput())->toContain("[WARN] Skill {$folder} lies on the disk of the asset container 'images'");
});

it('does not take a folder that only shares a prefix with the public directory for a public one', function () {
    $folder = doctorSkillFolder(public_path().'-skills', ['SKILL.md' => "---\nname: demo\ndescription: A demo.\n---\n"]);
    Server::registerAddonSkills($folder);

    Artisan::call('statamic:mcp:doctor');

    expect(doctorSkillsOutput())->toContain('[ OK ] 1 skill(s) valid.')->not->toContain('public directory');
});

it('warns when add-on skills never reach the configured server', function () {
    Server::registerAddonSkills(SkillsServer::FIXTURES.'/seo-audit');
    config(['statamic.mcp.server' => PlainMcpServer::class]);

    Artisan::call('statamic:mcp:doctor');

    expect(doctorSkillsOutput())->toContain('[WARN] Add-ons registered skills ('.SkillsServer::FIXTURES.'/seo-audit), but '.PlainMcpServer::class.' does not extend Danielgnh\StatamicMcp\Server, so the AI does not get them.');
});
