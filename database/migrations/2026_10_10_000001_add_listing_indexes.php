<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices para los patrones de consulta REALES de los listados y reportes.
 *
 * Hasta ahora había índices por clave foránea, pero no para lo que de verdad
 * hace la API: «los últimos N de mi centro» (WHERE institution_id = ? ORDER BY
 * created_at DESC LIMIT N), «lo que creó este docente», «los avisos de esta
 * aula». Sin ellos cada listado leía la tabla entera y la ordenaba: con un
 * centro de 1.500 estudiantes (15.000 recomendaciones, 13.000 intentos) ya se
 * veían recorridos completos, y crecen cada año.
 *
 * Postgres recorre un índice ascendente también hacia atrás, así que `(col, fecha)`
 * sirve para `ORDER BY fecha DESC`.
 *
 * Solo están los que el optimizador USA con un centro de volumen (1.500 estudiantes,
 * 15.000 recomendaciones, 13.000 intentos, 260.000 respuestas): se probaron más y
 * se descartaron los que no entraban en ningún plan (notificaciones por usuario,
 * historial por estudiante, un índice cubriente de intentos) para no cargar las
 * escrituras con índices inútiles.
 */
return new class extends Migration
{
    /** @var array<string,string> nombre => definición (sin CREATE INDEX) */
    private const INDICES = [
        // Exámenes: «los de mi centro, recientes», «los que creó este docente», por materia
        // (el catálogo cuenta los exámenes de cada materia).
        'idx_exams_institucion_creado'         => 'exams (institution_id, created_at)',
        'idx_exams_docente'                    => 'exams (created_by_teacher_id)',
        'idx_exams_materia'                    => 'exams (subject_id)',

        // Avisos: los de un aula (lo que ve el alumnado), por fecha de inicio y por autor.
        'idx_calendar_events_aula'             => 'calendar_events (group_id)',
        'idx_calendar_events_institucion_inicio' => 'calendar_events (institution_id, start_at)',
        'idx_calendar_events_autor'            => 'calendar_events (created_by)',

        // Recursos de estudio.
        'idx_study_resources_institucion_creado' => 'study_resources (institution_id, created_at)',
        'idx_study_resources_autor'            => 'study_resources (created_by)',

        // «Lo más reciente» del centro: con el índice se leen N filas en vez de leer y
        // ordenar toda la tabla (medido: ~4 ms → 0,06 ms con 15.000 filas).
        'idx_student_progress_institucion_actualizado' => 'student_progress (institution_id, updated_at)',
        'idx_ai_recommendations_institucion_creado'    => 'ai_recommendations (institution_id, created_at)',

        // Directorio de personal: solo administradores y docentes, que son una fracción
        // mínima de la tabla (el alumnado es casi todo). Parcial: no indexa a los
        // estudiantes. Medido: 0,11 ms frente a leer los 1.541 usuarios.
        'idx_users_personal'                   => "users (institution_id, created_at) WHERE user_type <> 'student'",

        // Matrículas ABIERTAS de un estudiante: lo consulta cada petición del alumnado
        // («¿en qué aulas está ahora?») y el alcance del docente. Parcial y cubriente
        // (`group_id` va dentro del índice: no se lee la tabla).
        'idx_group_students_abiertas'          => 'group_students (student_user_id) INCLUDE (group_id) WHERE left_at IS NULL',
    ];

    public function up(): void
    {
        foreach (self::INDICES as $nombre => $definicion) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$nombre} ON {$definicion}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDICES) as $nombre) {
            DB::statement("DROP INDEX IF EXISTS {$nombre}");
        }
    }
};
