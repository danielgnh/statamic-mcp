<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Skills;

use Laravel\Mcp\Server\Skill;
use RuntimeException;

class UnregistrableSkill extends Skill
{
    public function path(): string
    {
        return dirname(__DIR__, 2).'/__fixtures__/skills/seo-audit';
    }

    public function shouldRegister(): bool
    {
        throw new RuntimeException('license server down');
    }
}
