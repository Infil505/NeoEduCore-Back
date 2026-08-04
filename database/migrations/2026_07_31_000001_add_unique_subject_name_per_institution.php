<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El nombre de la materia debe ser único dentro de cada institución,
     * ignorando mayúsculas/minúsculas y espacios sobrantes.
     *
     * Pueden coexistir "Matemática 1er grado" y "Matemática 2do grado",
     * pero no dos "Matemática" ni "matemática " / "Matemática".
     */
    public function up(): void
    {
        // Con duplicados preexistentes el índice no se puede crear. Abortamos
        // con un mensaje accionable en vez de dejar la migración a medias.
        $duplicados = DB::select("
            SELECT institution_id, lower(btrim(name)) AS nombre, count(*) AS total
            FROM subjects
            GROUP BY institution_id, lower(btrim(name))
            HAVING count(*) > 1
        ");

        if (!empty($duplicados)) {
            $detalle = collect($duplicados)
                ->map(fn ($d) => "\"{$d->nombre}\" x{$d->total} (institución {$d->institution_id})")
                ->implode('; ');

            throw new RuntimeException(
                'No se puede aplicar UNIQUE sobre subjects: hay nombres duplicados. '
                . 'Renómbralos o elimínalos antes de migrar → ' . $detalle
            );
        }

        DB::statement('
            CREATE UNIQUE INDEX subjects_institution_name_unique
            ON subjects (institution_id, lower(btrim(name)))
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS subjects_institution_name_unique');
    }
};
