<?php

namespace Danielgnh\StatamicMcp\Support;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Fields\Field;

class TextEdit
{
    private const array TYPES = ['bard', 'markdown', 'text', 'textarea'];

    private const int SNIPPET_LENGTH = 40;

    private const int SNIPPETS = 3;

    private const array LOOSE = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{2032}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"',
        "\u{2011}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{00A0}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ',
    ];

    public static function supports(?Field $field): bool
    {
        return in_array($field?->type(), self::TYPES, true);
    }

    public static function accepts(?Field $field, mixed $patch): bool
    {
        return self::supports($field)
            && is_array($patch)
            && (array_key_exists('find', $patch) || array_key_exists('replace', $patch));
    }

    public static function isEdit(mixed $value): bool
    {
        return is_array($value) && count($value) === 2 && array_key_exists('find', $value) && array_key_exists('replace', $value);
    }

    /**
     * @param  array<array-key, mixed>  $edit
     */
    public static function apply(Field $field, mixed $value, array $edit, string $path): mixed
    {
        $find = $edit['find'] ?? null;
        $replace = $edit['replace'] ?? null;

        if (! self::isEdit($edit) || ! is_string($find) || $find === '' || ! is_string($replace)) {
            throw new ToolException(sprintf('%s takes its new value, or {"find": "...", "replace": "..."} to change one piece of its text', $path));
        }

        return match (true) {
            is_string($value) => self::inString($value, $find, $replace, $path),
            $field->type() === 'bard' && is_array($value) => self::inNodes($value, $find, $replace, $path),
            default => throw new ToolException("{$path} has no text to find in"),
        };
    }

    private static function inString(string $value, string $find, string $replace, string $path): string
    {
        $count = substr_count($value, $find);

        if ($count !== 1) {
            throw self::miss($path, $find, $count, [$value]);
        }

        return Str::replaceFirst($find, $replace, $value);
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @return array<array-key, mixed>
     */
    private static function inNodes(array $nodes, string $find, string $replace, string $path): array
    {
        $root = [['content' => $nodes]];
        $runs = self::runs($root);

        $matches = [];

        foreach ($runs as $run) {
            for ($offset = 0; ($position = strpos($run['text'], $find, $offset)) !== false; $offset = $position + strlen($find)) {
                $matches[] = [$run, $position];
            }
        }

        $blocks = collect($runs)->groupBy(fn (array $run) => implode('.', $run['path']))->map(fn (Collection $runs) => $runs->pluck('text')->implode(''))->values();
        $count = max(count($matches), $blocks->sum(fn (string $text) => substr_count($text, $find)));

        if ($count !== 1) {
            throw $count === 0 && self::inSets($nodes, $find)
                ? new ToolException("find matches text inside a Bard set in {$path}, which find and replace cannot reach — send the whole field")
                : self::miss($path, $find, $count, $blocks->all());
        }

        if ($matches === []) {
            $snippet = $blocks->flatMap(fn (string $text) => self::snippets($text, $find, false))->first();

            throw new ToolException("find crosses formatting such as bold, a link, or a line break in {$path}: {$snippet} — match a part of the text inside one formatting run, or send the whole field");
        }

        [$run, $position] = $matches[0];

        $content = &$root;

        foreach ($run['path'] as $index) {
            $content = &$content[$index]['content'];
        }

        $text = substr_replace($run['text'], $replace, $position, strlen($find));

        array_splice($content, $run['from'], $run['to'] - $run['from'], $text === '' ? [] : [[...$content[$run['from']], 'text' => $text]]);

        unset($content);

        return $root[0]['content'];
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @param  list<int|string>  $path
     * @return list<array{path: list<int|string>, from: int, to: int, marks: mixed, text: string}>
     */
    private static function runs(array $nodes, array $path = []): array
    {
        $runs = [];

        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ! is_array($node['content'] ?? null) || ($node['type'] ?? null) === 'set') {
                continue;
            }

            $here = [...$path, $index];
            $run = null;

            foreach (array_values($node['content']) as $position => $child) {
                $text = is_array($child) && ($child['type'] ?? null) === 'text' ? ($child['text'] ?? null) : null;

                if (is_string($text) && $run !== null && $run['marks'] === ($child['marks'] ?? null)) {
                    $run['text'] .= $text;
                    $run['to'] = $position + 1;

                    continue;
                }

                if ($run !== null) {
                    $runs[] = $run;
                    $run = null;
                }

                if (is_string($text)) {
                    $run = ['path' => $here, 'from' => $position, 'to' => $position + 1, 'marks' => $child['marks'] ?? null, 'text' => $text];
                }
            }

            if ($run !== null) {
                $runs[] = $run;
            }

            $runs = [...$runs, ...self::runs($node['content'], $here)];
        }

        return $runs;
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     */
    private static function inSets(array $nodes, string $find): bool
    {
        $found = false;

        foreach ($nodes as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'set' && is_array($values = data_get($node, 'attrs.values'))) {
                array_walk_recursive($values, function (mixed $value) use ($find, &$found) {
                    $found = $found || (is_string($value) && str_contains($value, $find));
                });
            }
        }

        return $found;
    }

    /**
     * @param  array<array-key, string>  $texts
     */
    private static function miss(string $path, string $find, int $count, array $texts): ToolException
    {
        if ($count > 1) {
            $snippets = collect($texts)->flatMap(fn (string $text) => self::snippets($text, $find, false))->take(self::SNIPPETS)->implode(' | ');

            return new ToolException(sprintf('find matches %d times in %s — add words around it so it matches once: %s', $count, $path, $snippets));
        }

        $similar = collect($texts)->flatMap(fn (string $text) => self::snippets($text, $find, true))->take(self::SNIPPETS);

        return new ToolException($similar->isEmpty()
            ? "find matches nothing in {$path} — it must match the stored text exactly once; read the field to copy it"
            : sprintf('find matches nothing in %s — it must match the stored text exactly; similar: %s', $path, $similar->implode(' | ')));
    }

    /**
     * @return list<string>
     */
    private static function snippets(string $text, string $find, bool $loose): array
    {
        $haystack = $loose ? strtr($text, self::LOOSE) : $text;
        $needle = $loose ? strtr($find, self::LOOSE) : $find;
        $snippets = [];

        for ($offset = 0; count($snippets) < self::SNIPPETS && ($position = $loose ? mb_stripos($haystack, $needle, $offset) : mb_strpos($haystack, $needle, $offset)) !== false; $offset = $position + mb_strlen($needle)) {
            $start = max(0, $position - self::SNIPPET_LENGTH);

            $snippets[] = '…'.mb_substr($text, $start, $position - $start + mb_strlen($needle) + self::SNIPPET_LENGTH).'…';
        }

        return $snippets;
    }
}
