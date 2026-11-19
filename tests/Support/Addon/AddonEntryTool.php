<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Danielgnh\StatamicMcp\Tools\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('addon_entry_title')]
#[Description('Returns the title of an entry the acting user may edit.')]
#[IsReadOnly]
class AddonEntryTool extends Tool
{
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->required()];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['id' => 'required|string']);
        $user = $this->user($request);

        $entry = $this->findExposedEntry($validated['id'], $user);
        $this->ensureEntryPermission($user, 'edit', $entry);

        return $this->json(['title' => $entry->get('title')]);
    }
}
