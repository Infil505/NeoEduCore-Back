<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;

/**
 * Una fecha de texto que PostgreSQL pueda guardar y que tenga sentido.
 *
 * La regla `date` de Laravel acepta casi cualquier cosa que `strtotime` entienda:
 * `@99999999999999` o un número de tres cientos dígitos pasan la validación y
 * revientan en la base (`timestamp out of range`) como un 500. Además la regla
 * `after_or_equal:otro_campo` lanza un error si el otro campo llega como array.
 *
 * Esta regla exige **texto** y acota el año a un rango razonable
 * (1900–2200), de sobra para un calendario escolar y muy dentro de lo que cabe
 * en un `timestamp`.
 */
class FechaRazonable implements ValidationRule
{
    private const ANIO_MIN = 1900;
    private const ANIO_MAX = 2200;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // `strtotime('2026-02-30')` da el 2 de marzo: la acepta, y PostgreSQL rechaza
        // el literal tal cual. `date_parse` avisa («The parsed date was invalid»).
        $analisis = is_string($value) ? date_parse($value) : null;
        $marca = $analisis !== null && $analisis['error_count'] === 0 && $analisis['warning_count'] === 0
            ? strtotime($value)
            : false;

        if ($marca === false) {
            $fail('El campo :attribute no es una fecha válida.');

            return;
        }

        $anio = (int) date('Y', $marca);

        if ($anio < self::ANIO_MIN || $anio > self::ANIO_MAX) {
            $fail('El campo :attribute debe estar entre los años ' . self::ANIO_MIN . ' y ' . self::ANIO_MAX . '.');
        }
    }

    /**
     * `after_or_equal:$campo`, pero **solo si ese campo llegó como texto**: con un
     * array o un número, la regla estándar lanza un error (500) al compararlos, y
     * en ese caso el propio `$campo` ya falla su validación por su cuenta.
     *
     * @return array<int,string>
     */
    public static function posteriorA(Request $request, string $campo): array
    {
        return is_string($request->input($campo)) ? ["after_or_equal:{$campo}"] : [];
    }
}
