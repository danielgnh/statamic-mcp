<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Str;
use JsonSerializable;
use Statamic\Fields\Blueprint;

trait PreviewsRichText
{
    // Bytes (strlen) of encoded JSON before truncation — byte-based on purpose:
    // it approximates token cost; multibyte characters count per-byte.
    private const int PREVIEW_THRESHOLD = 500;

    // Characters of plain-text preview kept (Str::limit is mb-safe — never cuts mid-character).
    private const int PREVIEW_LENGTH = 300;

    /**
     * @param  list<string>  $requestedFields
     */
    private function assertKnownFields(array $requestedFields, Blueprint $blueprint): void
    {
        if ($requestedFields === []) {
            return;
        }

        // 'slug' is v6's auto-injected blueprint field (ensure*BlueprintFields);
        // it lives outside data() and is always returned as a top-level response key,
        // so it's never a selectable data field.
        $handles = $blueprint->fields()->all()->keys()->reject(fn ($handle) => $handle === 'slug')->values()->all();
        $unknown = array_values(array_diff($requestedFields, $handles));

        if ($unknown === []) {
            return;
        }

        sort($handles);

        throw new ToolException(sprintf(
            'unknown field%s %s — valid handles: %s',
            count($unknown) === 1 ? '' : 's',
            implode(', ', $unknown),
            implode(', ', $handles),
        ));
    }

    /**
     * Long Bard/markdown values become {__preview, truncated, note} objects
     * unless explicitly requested via fields.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $requestedFields
     * @return array<string, mixed>
     */
    private function withRichTextPreviews(array $data, Blueprint $blueprint, array $requestedFields): array
    {
        foreach ($data as $handle => $value) {
            if (in_array($handle, $requestedFields, true)) {
                continue;
            }

            $field = $blueprint->fields()->all()->get($handle);
            if (! $field) {
                continue;
            }
            if (! in_array($field->type(), ['bard', 'markdown'], true)) {
                continue;
            }

            // Augmented values are JsonSerializable wrappers; normalize before measuring.
            $raw = $value instanceof JsonSerializable ? $value->jsonSerialize() : $value;

            $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                continue;
            }
            if (strlen($encoded) <= self::PREVIEW_THRESHOLD) {
                continue;
            }

            $data[$handle] = [
                '__preview' => Str::limit($this->plainText($raw), self::PREVIEW_LENGTH),
                'truncated' => true,
                'note' => sprintf('NOT writable — fetch raw field before editing: %s with fields: ["%s"]', $this->name(), $handle),
            ];
        }

        return $data;
    }

    /**
     * Extract readable text from a ProseMirror document (Bard stores
     * {type, content: [{type: text, text: ...}]} trees) or pass strings through.
     */
    private function plainText(mixed $value): string
    {
        if (is_string($value)) {
            return strip_tags($value); // augmented bard is HTML — markup wastes preview budget
        }

        if (! is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $text = '';

        array_walk_recursive($value, function ($item, $key) use (&$text) {
            if ($key === 'text' && is_string($item)) {
                $text .= $item.' ';
            }
        });

        return trim($text);
    }
}
