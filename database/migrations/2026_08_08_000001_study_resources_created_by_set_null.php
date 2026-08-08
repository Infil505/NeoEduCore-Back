<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `study_resources.created_by` → ON DELETE SET NULL.
 *
 * Era la última FK a `users(id)` que seguía en NO ACTION, y con ella
 * `DELETE /api/users/{id}` **devolvía 500** en cuanto el docente hubiera subido
 * un solo recurso de estudio: `UserController::destroy()` hace `$user->delete()`
 * sin ningún manejo de violación de FK, así que la excepción de PostgreSQL sale
 * tal cual al cliente.
 *
 * Es la misma familia de fallo que corrigió `align_fk_constraints_with_tfg_model`
 * (materias, grupos, exámenes y usuarios daban 500 al borrar con contenido), y se
 * escapó por la misma razón: `CascadeIntegrityTest::test_deleting_a_teacher_keeps_their_exams`
 * monta exámenes para el docente, pero no recursos de estudio.
 *
 * **Por qué el modelo del TFG no lo cubre.** El esquema de referencia declara
 * esta FK sin `ON DELETE`, igual que la implementación, así que la comparación de
 * `ANALISIS_MODELO_DATOS_TFG.md` §3 no la marcó como divergencia: ambas partes
 * coincidían en el mismo hueco. Aquí se elige apartarse de la referencia por
 * coherencia con las otras dos FK de autoría, que ya son SET NULL:
 *
 *   - `exams.created_by_teacher_id`  → SET NULL (divergencia deliberada, §4)
 *   - `calendar_events.created_by`   → SET NULL (lo pide el propio TFG)
 *
 * El criterio es el mismo que justificó la de `exams`: dar de baja a un docente
 * no debe destruir el material que dejó al centro, ni quedar bloqueado por él.
 * La columna ya es nullable, así que no hace falta tocar su definición.
 */
return new class extends Migration
{
    private const TABLA = 'study_resources';
    private const FK    = 'study_resources_created_by_foreign';

    public function up(): void
    {
        $this->recrear('ON DELETE SET NULL');
    }

    public function down(): void
    {
        $this->recrear('');
    }

    private function recrear(string $onDelete): void
    {
        DB::statement('ALTER TABLE ' . self::TABLA . ' DROP CONSTRAINT IF EXISTS ' . self::FK);
        DB::statement(
            'ALTER TABLE ' . self::TABLA . ' ADD CONSTRAINT ' . self::FK
            . ' FOREIGN KEY (created_by) REFERENCES users(id) ' . $onDelete
        );
    }
};
