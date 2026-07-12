<?php

namespace Danielgnh\StatamicMcp\Setup;

/**
 * Ensures config/auth.php has 'api' => ['driver' => 'passport', ...] under
 * 'guards': inserts the guard after the 'guards' => [ anchor, or rewrites the
 * driver of an existing 'api' guard in place. Anything unexpected bails so
 * the caller prints the manual snippet instead of guessing.
 */
class AuthGuardEditor
{
    public function apply(string $path): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);

        // The api guard holds only scalar entries, so the block reliably ends
        // at the first ']' — no balancing needed.
        if (preg_match("/'api'\s*=>\s*\[[^\]]*\]/s", $contents, $matches)) {
            return $this->rewriteExistingGuard($path, $contents, $matches[0]);
        }

        return $this->insertGuard($path, $contents);
    }

    protected function rewriteExistingGuard(string $path, string $contents, string $block): EditResult
    {
        if (preg_match("/'driver'\s*=>\s*'passport'/", $block)) {
            return EditResult::Skipped;
        }

        if (! preg_match("/'driver'\s*=>\s*'[^']*'/", $block)) {
            return EditResult::Bailed;
        }

        $rewritten = preg_replace("/'driver'\s*=>\s*'[^']*'/", "'driver' => 'passport'", $block, 1);

        file_put_contents($path, str_replace($block, $rewritten, $contents));

        return EditResult::Applied;
    }

    protected function insertGuard(string $path, string $contents): EditResult
    {
        if (! preg_match("/'guards'\s*=>\s*\[\n/", $contents, $matches)) {
            return EditResult::Bailed;
        }

        $guard = "        'api' => [\n            'driver' => 'passport',\n            'provider' => 'users',\n        ],\n\n";

        file_put_contents($path, str_replace($matches[0], $matches[0].$guard, $contents));

        return EditResult::Applied;
    }

    public function snippet(): string
    {
        return <<<'PHP'
'guards' => [
    // ...
    'api' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],
],
PHP;
    }
}
