<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Borrador de respuestas del intento (14/10/2026).
 *
 * Hasta ahora el servidor no sabía nada de las respuestas hasta el `submit`: si se agotaba el tiempo y el alumno
 * había cerrado la pestaña o perdido la conexión, el intento quedaba abierto para siempre y perdía todo.
 *
 *  - `draft_answers`: lo que el alumno tenía marcado la última vez que el frontend lo guardó (mismo formato que
 *    `answers` del submit). Lo borra la entrega, venga del alumno o del cierre automático.
 *  - `draft_saved_at`: cuándo se guardó, para que el docente o el soporte sepan hasta qué momento llega.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.exam_attempts ADD COLUMN draft_answers jsonb');
        DB::statement('ALTER TABLE public.exam_attempts ADD COLUMN draft_saved_at timestamp(0) without time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.exam_attempts DROP COLUMN IF EXISTS draft_saved_at, DROP COLUMN IF EXISTS draft_answers');
    }
};
