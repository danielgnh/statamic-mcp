<?php

namespace Danielgnh\StatamicMcp\Setup;

/**
 * Adds the Laravel\Passport\HasApiTokens trait — and, when the installed
 * Passport version ships it, the OAuthenticatable contract — to the user
 * model. Pure string insertion anchored on a single-line class declaration;
 * any model that doesn't match the expected shape (multi-line declaration,
 * a competing HasApiTokens like Sanctum's) bails to the manual snippet.
 */
class UserModelEditor
{
    protected const TRAIT = 'Laravel\Passport\HasApiTokens';

    public function apply(string $path, ?string $interface): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return EditResult::Bailed;
        }

        if (str_contains($contents, self::TRAIT)) {
            return EditResult::Skipped;
        }

        // A different HasApiTokens (Sanctum's) would collide with ours on the
        // unqualified name — a human must resolve that, not a regex.
        if (str_contains($contents, 'HasApiTokens')) {
            return EditResult::Bailed;
        }

        // Anchor: a single-line class declaration, e.g.
        // "class User extends Authenticatable" or "... implements MustVerifyEmail".
        if (! preg_match('/^(class\s+\w+[^\n{]*)$/m', $contents, $declaration)) {
            return EditResult::Bailed;
        }

        // A declaration line ending in "implements" means the interface list
        // continues on the next line — out of anchor-based reach, bail.
        if (preg_match('/\bimplements\s*$/', $declaration[1])) {
            return EditResult::Bailed;
        }

        $updated = $contents;

        if ($interface !== null && ! str_contains($contents, class_basename($interface))) {
            $line = $declaration[1];

            $newLine = str_contains($line, 'implements')
                ? rtrim($line).', '.class_basename($interface)
                : rtrim($line).' implements '.class_basename($interface);

            $updated = str_replace($line, $newLine, $updated);
        }

        if (! preg_match('/^class\s+\w+[^{]*\{\n/m', $updated, $body)) {
            return EditResult::Bailed;
        }

        $updated = str_replace($body[0], $body[0]."    use HasApiTokens;\n", $updated);

        $updated = $this->addImports($updated, $interface);

        if ($updated === null) {
            return EditResult::Bailed;
        }

        file_put_contents($path, $updated);

        return EditResult::Applied;
    }

    protected function addImports(string $contents, ?string $interface): ?string
    {
        $imports = 'use '.self::TRAIT.";\n";

        if ($interface !== null && ! str_contains($contents, 'use '.$interface.';')) {
            $imports .= 'use '.$interface.";\n";
        }

        if (! preg_match('/^namespace\s+[^;]+;\n\n/m', $contents, $matches)) {
            return null;
        }

        return str_replace($matches[0], $matches[0].$imports, $contents);
    }

    public function snippet(?string $interface): string
    {
        $implements = $interface ? ' implements \\'.$interface : '';

        return <<<PHP
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable{$implements}
{
    use HasApiTokens;
    // ...
}
PHP;
    }
}
