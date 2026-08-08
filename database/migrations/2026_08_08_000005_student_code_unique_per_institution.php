<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `students.student_code` pasa a ser único **por institución**.
 *
 * La constraint original era `UNIQUE (student_code)` a secas, es decir global a
 * toda la plataforma. En un SaaS multi-tenant eso es un error de modelo: el
 * código de estudiante es un identificador **interno de cada centro** —el que
 * aparece en sus listas y expedientes— y dos colegios distintos tienen todo el
 * derecho a numerar «EST-0001» cada uno.
 *
 * Cómo se descubrió (08/08/2026): la comprobación de duplicados de la carga
 * masiva iba acotada al tenant, coherente con el modelo pero **no con la
 * constraint**. Un código ya usado por otro centro pasaba el filtro de la
 * aplicación y reventaba contra la base; y como en PostgreSQL una violación de
 * constraint aborta la transacción entera, se perdía el archivo completo
 * —incluidas las filas correctas— informando de un único error de fila.
 *
 * La constraint nueva es más permisiva que la anterior, así que cualquier dato
 * que cumpliera la global cumple también la compuesta: la migración no puede
 * fallar por datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE students DROP CONSTRAINT IF EXISTS students_student_code_unique');

        DB::statement(
            'ALTER TABLE students
                ADD CONSTRAINT students_institucion_codigo_unique
                UNIQUE (institution_id, student_code)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE students DROP CONSTRAINT IF EXISTS students_institucion_codigo_unique');

        // Al revertir puede fallar legítimamente: si dos instituciones llegaron a
        // usar el mismo código —que es justo lo que esta migración permite—, la
        // constraint global ya no es satisfacible. Se avisa en vez de dejar un
        // error opaco de PostgreSQL.
        $colisiones = DB::table('students')
            ->select('student_code')
            ->whereNotNull('student_code')
            ->groupBy('student_code')
            ->havingRaw('count(*) > 1')
            ->count();

        if ($colisiones > 0) {
            throw new RuntimeException(
                "Hay {$colisiones} student_code repetidos entre instituciones. " .
                'No se puede restaurar la constraint global sin renumerarlos primero.'
            );
        }

        DB::statement('ALTER TABLE students ADD CONSTRAINT students_student_code_unique UNIQUE (student_code)');
    }
};
