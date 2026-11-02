<?php

namespace App;

final class PasswordPolicy
{
    public const MIN_LENGTH = 6;

    public const MAX_LENGTH = 12;

    public static function rules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'min:'.self::MIN_LENGTH, 'max:'.self::MAX_LENGTH];
    }
}
