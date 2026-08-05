<?php

namespace Database\Factories\Academic;

use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectFactory extends Factory
{
    protected $model = \App\Models\Academic\Subject::class;

    /** Contador de proceso: garantiza nombres distintos entre llamadas. */
    private static int $secuencia = 0;

    public function definition(): array
    {
        // El nombre es UNIQUE por institución (índice sobre lower(btrim(name))),
        // así que componemos "Materia N° grado" como en el catálogo real en vez
        // de sortear entre un puñado de nombres que colisionarían.
        $materias = ['Matemáticas', 'Español', 'Ciencias', 'Inglés', 'Historia'];
        $n = ++self::$secuencia;

        return [
            'institution_id' => Institution::factory(),
            'name' => $materias[$n % count($materias)] . " {$n}° grado",
        ];
    }
}
