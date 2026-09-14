<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Formaliza `ENABLE ROW LEVEL SECURITY` en el esquema (decisión D8).
 *
 * Hasta aquí lo ponía Supabase por su cuenta y **ninguna migración lo declaraba**.
 * Eso tenía tres consecuencias molestas:
 *
 * 1. `php artisan schema:dump-sql` lo borraba en cada regeneración, porque un
 *    `pg_dump` contra la base local no lo encuentra. El bloque había que volver
 *    a pegarlo a mano al final del fichero, con un comentario avisando. Se
 *    olvidó más de una vez.
 * 2. Un PostgreSQL que no fuera Supabase —otro proveedor, un contenedor -
 *    nacía sin RLS y nadie se enteraba.
 * 3. El esquema no describía el estado real de la base de producción, que es
 *    justo lo que un artefacto generado debería hacer.
 *
 * **Qué hace y qué no.** Activar RLS *sin políticas* no bloquea al propietario
 * de la tabla, así que para la aplicación —que conecta con ese rol— es inocuo:
 * ni una consulta cambia de resultado. Lo que cierra es el acceso de los roles
 * **anónimo y autenticado** de PostgREST, la API REST automática que Supabase
 * expone sobre la misma base. Sin RLS, esa API deja los datos de menores a un
 * `curl` de distancia; es el estado por defecto de Supabase y por eso lo activa.
 *
 * El aislamiento real entre instituciones **no depende de esto**: lo hace
 * `TenantScoped` en la capa de aplicación. Esta migración es la segunda puerta.
 *
 * Es idempotente: `ENABLE ROW LEVEL SECURITY` sobre una tabla que ya lo tiene no
 * da error, así que aplicarla en Supabase —donde ya estaba— no cambia nada.
 */
return new class extends Migration
{
    /**
     * Las 19 tablas de dominio más las 5 del framework. Se incluyen también las
     * del framework a propósito: `personal_access_tokens` guarda los hashes de
     * sesión y `password_reset_tokens` los de recuperación — son, si acaso, más
     * sensibles que las de dominio.
     */
    private const TABLAS = [
        'ai_chat_sessions', 'ai_recommendations', 'ai_tutor_incidents', 'calendar_events',
        'exam_attempts', 'exam_targets', 'exams', 'group_students', 'groups',
        'institutions', 'question_options', 'questions', 'student_answer_options',
        'student_answers', 'student_progress', 'student_subjects', 'students',
        'study_resources', 'subjects', 'teacher_assignments', 'users',

        // Framework
        'failed_jobs', 'jobs', 'migrations', 'password_reset_tokens', 'personal_access_tokens',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            DB::statement("ALTER TABLE public.{$tabla} ENABLE ROW LEVEL SECURITY");
        }
    }

    /**
     * Revertir deja las tablas expuestas a la API automática de Supabase, que es
     * peor que el estado anterior a esta migración. Se implementa porque una
     * migración sin `down()` no se puede probar, pero no es algo que convenga
     * ejecutar en un entorno real.
     */
    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            DB::statement("ALTER TABLE public.{$tabla} DISABLE ROW LEVEL SECURITY");
        }
    }
};
