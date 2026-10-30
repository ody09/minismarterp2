<?php

namespace App\Support;

class FormValue
{
    public static function old(string $key, mixed $default = null): string|int|float|bool|null
    {
        $value = old($key, $default);

        return is_scalar($value) || $value === null ? $value : (is_scalar($default) ? $default : null);
    }
}
