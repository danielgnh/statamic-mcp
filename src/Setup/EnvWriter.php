<?php

namespace Danielgnh\StatamicMcp\Setup;

/**
 * Sets KEY=value in a dotenv file: replaces the existing assignment when the
 * key is present, appends otherwise. Never touches any other line.
 */
class EnvWriter
{
    public function apply(string $path, string $key, string $value): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);
        $pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';

        if (preg_match($pattern, $contents, $matches)) {
            if (trim($matches[1]) === $value) {
                return EditResult::Skipped;
            }

            file_put_contents($path, preg_replace($pattern, $key.'='.$value, $contents, 1));

            return EditResult::Applied;
        }

        file_put_contents($path, rtrim($contents, "\n")."\n".$key.'='.$value."\n");

        return EditResult::Applied;
    }

    public function snippet(string $key, string $value): string
    {
        return $key.'='.$value;
    }
}
