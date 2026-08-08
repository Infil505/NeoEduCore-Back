<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cierra la coherencia de las FK de `institution_id`.
 *
 * Hasta aquí, de las 12 columnas `institution_id` del modelo:
 *
 *   - 10 tenían FK con `ON DELETE CASCADE`
 *   - 2 tenían FK con `NO ACTION` (`students`, `group_students`)
 *   - 2 **no tenían FK ninguna** (`exam_attempts`, `student_progress`)
 *
 * Los cuatro casos raros venían de la misma causa: `align_fk_constraints_with_tfg_model`
 * (03/08/2026) se ciñó a lo que declaraba el esquema de diseño del TFG, y ese
 * documento no cubría estos cuatro. La decisión quedó anotada como abierta en
 * `ANALISIS_MODELO_DATOS_TFG.md` §7.2, planteada como «coherencia del modelo vs.
 * fidelidad al documento de referencia».
 *
 * **Esa tensión ya no existe.** El criterio del proyecto es que manda el sistema
 * construido y el informe se ajusta a él, no al revés: el esquema del TFG es un
 * diseño preliminar, no una autoridad sobre el entregable. Con eso, la decisión se
 * resuelve sola por el lado de la coherencia.
 *
 * ## Qué cambia en la práctica
 *
 * - **Las dos FK nuevas** cierran el mismo agujero de aislamiento multi-tenant que
 *   se tapó en `ai_recommendations`, `question_options` y `student_answers`: la
 *   columna existía sin restricción, así que se podía escribir ahí el id de una
 *   institución inexistente.
 * - **Las dos que pasan a CASCADE** son hoy inalcanzables: no hay `DELETE
 *   /institutions`, solo `PATCH /institutions/{id}/toggle`. Se arreglan ahora
 *   precisamente por eso — cuando ese endpoint exista, `NO ACTION` en `students`
 *   haría fallar el borrado con un 500, que es exactamente el fallo que costó
 *   descubrir en abril con materias, grupos y exámenes.
 *
 * Resultado: **las 12 columnas `institution_id` con FK y todas en CASCADE**, y 47 FK
 * en total.
 */
return new class extends Migration
{
    /** FK que no existían: al revertir solo se eliminan. */
    private const NUEVAS = [
        ['t' => 'exam_attempts',    'n' => 'exam_attempts_institution_id_foreign'],
        ['t' => 'student_progress', 'n' => 'student_progress_institution_id_foreign'],
    ];

    /** FK existentes que pasan de NO ACTION a CASCADE. */
    private const ENDURECIDAS = [
        ['t' => 'students',       'n' => 'students_institution_id_foreign'],
        ['t' => 'group_students', 'n' => 'group_students_institution_id_foreign'],
    ];

    public function up(): void
    {
        $this->abortarSiHayHuerfanos();

        foreach (array_merge(self::NUEVAS, self::ENDURECIDAS) as $fk) {
            $this->recrear($fk['t'], $fk['n'], 'ON DELETE CASCADE');
        }
    }

    public function down(): void
    {
        foreach (self::NUEVAS as $fk) {
            DB::statement("ALTER TABLE {$fk['t']} DROP CONSTRAINT IF EXISTS {$fk['n']}");
        }

        foreach (self::ENDURECIDAS as $fk) {
            $this->recrear($fk['t'], $fk['n'], '');
        }
    }

    private function recrear(string $tabla, string $nombre, string $onDelete): void
    {
        DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$nombre}");
        DB::statement(
            "ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} "
            . "FOREIGN KEY (institution_id) REFERENCES institutions(id) {$onDelete}"
        );
    }

    /**
     * Las dos columnas sin FK nunca han tenido quien las valide, así que aquí sí
     * puede haber basura real. Mejor abortar con el diagnóstico que fallar a media
     * migración — mismo patrón que `align_fk_constraints_with_tfg_model`.
     */
    private function abortarSiHayHuerfanos(): void
    {
        $problemas = [];

        foreach (['exam_attempts', 'student_progress'] as $tabla) {
            $n = (int) DB::selectOne(
                "SELECT count(*) AS total FROM {$tabla} h
                  WHERE h.institution_id IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM institutions r WHERE r.id = h.institution_id)"
            )->total;

            if ($n > 0) {
                $problemas[] = "{$tabla}.institution_id → institutions(id): {$n} filas huérfanas";
            }
        }

        if (!empty($problemas)) {
            throw new RuntimeException(
                'No se puede cerrar la coherencia de tenant, hay datos que la violan. '
                . 'Límpialos antes de migrar → ' . implode('; ', $problemas)
            );
        }
    }
};
