<?php

namespace Danielgnh\StatamicMcp\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Statamic runs addon settings through Antlers every time it loads them, and
 * these are the sequences that make it render something: a tag, a component,
 * or a directive. Text containing one would run or fail on load instead of
 * reaching agents as written.
 */
class WithoutTemplateSyntax implements ValidationRule
{
    public const PATTERN = '/\{\{|<(?:s|statamic|x)[:-]|@(?:props|aware|cascade)/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match(self::PATTERN, $value) === 1) {
            $fail(__('Statamic would run {{ }}, Antlers and Blade component tags, and @props here as template code. Describe the tag in words instead.'));
        }
    }
}
