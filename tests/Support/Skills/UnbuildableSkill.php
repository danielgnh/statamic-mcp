<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Skills;

use Laravel\Mcp\Server\Skill;
use RuntimeException;

class UnbuildableSkill extends Skill
{
    public function __construct()
    {
        throw new RuntimeException('missing API key');
    }

    public function path(): string
    {
        return dirname(__DIR__, 2).'/__fixtures__/skills/seo-audit';
    }
}
