<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Skills;

use Danielgnh\StatamicMcp\Server;

class SkillsServer extends Server
{
    public const string FIXTURES = __DIR__.'/../../__fixtures__/skills';

    /** @var array<int, mixed> */
    protected array $skills = [
        self::FIXTURES.'/landing-page',
        PricingPlaybook::class,
        self::FIXTURES.'/shadow/seo-audit',
    ];
}
