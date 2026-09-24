<?php

namespace Danielgnh\StatamicMcp\Support;

use Illuminate\Support\Facades\File;

/**
 * Markdown guidelines for agents, under config('statamic.mcp.guidelines_path'):
 * site.md, then one file per resource and optionally per blueprint, laid out
 * like resources/blueprints (collections/pages.md, collections/pages/landing.md).
 */
class GuidelineFiles
{
    public function path(string $file = ''): string
    {
        $root = rtrim(config()->string('statamic.mcp.guidelines_path', resource_path('mcp/guidelines')), '/');

        return $file === '' ? $root : "{$root}/{$file}";
    }

    public function site(): ?string
    {
        return $this->read('site.md');
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'  $type
     */
    public function for(string $type, string $handle, string $blueprint): ?string
    {
        $guidelines = array_filter([
            $this->read("{$type}/{$handle}.md"),
            $this->read("{$type}/{$handle}/{$blueprint}.md"),
        ]);

        return $guidelines === [] ? null : implode("\n\n", $guidelines);
    }

    /**
     * HTML comments are notes for the developer and never reach an agent,
     * so an untouched stub reads as no guidelines at all. An unclosed comment
     * runs to the end of the file, as it does in any markdown preview.
     */
    public function read(string $file): ?string
    {
        $path = $this->path($file);

        if (! File::isFile($path)) {
            return null;
        }

        $markdown = trim((string) preg_replace('/<!--.*?(?:-->|\z)/s', '', File::get($path)));

        return filled($markdown) ? $markdown : null;
    }
}
