<?php

namespace App\Rules;

use App\Support\CatalogoMaterias;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La materia existe en el centro del usuario. Equivale a `Rule::exists('subjects', 'id')->where(
 * 'institution_id', …)` pero lee el catálogo en caché en vez de consultar la base: con la base
 * remota la regla costaba un viaje (~0,4 s) en cada creación de examen, recurso o inscripción.
 */
class MateriaDelCentro implements ValidationRule
{
    public function __construct(private readonly ?string $centro)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CatalogoMaterias::delCentro($this->centro)->has($value)) {
            $fail('validation.exists')->translate();
        }
    }
}
