<?php

namespace Danielgnh\StatamicMcp\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

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
