<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

/** DECIMAL(19,4): incoming JSON must be a decimal string, never a float/exponent. */
final class DecimalAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valid($value)) {
            $fail('El importe debe ser texto decimal no negativo, con hasta 15 enteros y 4 decimales.');
        }
    }

    private static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?$/D', $value) === 1;
    }

    public static function normalize(mixed $value): string
    {
        if (! self::valid($value)) {
            throw ValidationException::withMessages(['amount' => 'Importe decimal no válido.']);
        }

        return bcadd($value, '0', 4);
    }
}
