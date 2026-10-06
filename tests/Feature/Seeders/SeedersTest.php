<?php

namespace Tests\Feature\Seeders;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Database\Seeders\CoreTablesSeeder;
use Database\Seeders\LoadTestSeeder;
use Database\Seeders\TallerAulaSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los seeders producen datos que **cumplen las reglas del sistema** y en los que
 * cada rol ve lo que debe.
 *
 * Los datos de demostración son lo primero que mira cualquiera que abra el
 * sistema, y se quedaron desfasados sin que ningún test lo notara: el
 * `CoreTablesSeeder` usaba grados 9–12 (el sistema es de 1.º a 6.º), no creaba
 * asignaciones docente→aula→materia —así que ningún docente veía a nadie— y los
 * recursos no se enviaban a ningún aula, de modo que ningún estudiante los veía.
 *
 * Aquí no se comprueban cifras sueltas, sino **invariantes** del modelo sobre los
 * datos sembrados, y después se entra por la API con las cuentas sembradas.
 *
 * ⚠️ `TallerAulaSeeder` vacía la base antes de sembrar (conserva el superadmin).
 * Es seguro en la base de tests —cada test crea sus propios datos—, pero por eso
 * este archivo no debe ejecutarse contra otra base.
 */
class SeedersTest extends TestCase
{
    /* ============================================================
     | CoreTablesSeeder (el de `php artisan db:seed`)
     ============================================================ */

    private function sembrarCore(): string
    {
        // Borrar el centro CON sus cuentas, como hace DELETE /institutions/{id}:
        // `users.institution_id` es ON DELETE SET NULL y las cuentas quedarían huérfanas.
        $anterior = Institution::where('code', CoreTablesSeeder::CODIGO_INSTITUCION)->first();
        if ($anterior) {
            User::where('institution_id', $anterior->id)->delete();
            $anterior->delete();
        }
        User::where('email', 'like', '%@neoeducore.edu.co')->delete();   // huérfanas de un borrado a medias

        (new CoreTablesSeeder())->run();

        return Institution::where('code', CoreTablesSeeder::CODIGO_INSTITUCION)->firstOrFail()->id;
    }

    private function como(string $correo): User
    {
        $usuario = User::where('email', $correo)->firstOrFail();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    /** Total de filas de un listado paginado (`data.total`): no es lo mismo que la 1.ª página. */
    private function total(string $ruta): int
    {
        return (int) $this->getJson($ruta)->assertOk()->json('data.total');
    }

    private function ids(string $ruta, string $clave = 'id'): array
    {
        return collect($this->getJson($ruta)->assertOk()->json('data.data'))->pluck($clave)->all();
    }

    public function test_los_datos_de_core_cumplen_las_reglas_del_modelo(): void
    {
        $this->assertInvariantes($this->sembrarCore());
    }

    public function test_core_es_idempotente(): void
    {
        $this->sembrarCore();
        $antes = [Institution::count(), DB::table('users')->count(), DB::table('exams')->count()];

        (new CoreTablesSeeder())->run();   // el centro ya existe: no hace nada

        $this->assertSame($antes, [Institution::count(), DB::table('users')->count(), DB::table('exams')->count()]);
    }

    public function test_cada_rol_ve_lo_suyo_en_core(): void
    {
        $this->sembrarCore();
        $dominio = '@neoeducore.edu.co';

        // --- Docente 1: Matemáticas en 4-A y 4-B → ve a los 5 y solo su examen
        $this->como("profesor1{$dominio}");
        $this->assertCount(5, $this->ids('/api/students', 'user_id'));
        $this->assertSame(['Matemáticas'], $this->nombres('/api/subjects'));
        $this->assertSame(['Parcial 1 - Matemáticas'], $this->nombres('/api/exams', 'title'));
        $this->assertCount(2, $this->ids('/api/groups'));

        // --- Docente 2: Español e Inglés en 4-A → ve a 4 y SOLO su examen (no el del colega)
        $this->como("profesor2{$dominio}");
        $this->assertCount(4, $this->ids('/api/students', 'user_id'));
        $this->assertEqualsCanonicalizing(['Español', 'Inglés'], $this->nombres('/api/subjects'));
        $this->assertSame(['Borrador - Comprensión lectora'], $this->nombres('/api/exams', 'title'));
        $this->assertCount(1, $this->ids('/api/groups'));

        // --- Estudiante de 4-A: el examen activo de su aula, sus recursos y avisos
        $this->como("estudiante1{$dominio}");
        $this->assertSame(['Parcial 1 - Matemáticas'], $this->nombres('/api/exams', 'title'));   // el borrador no
        $this->assertCount(3, $this->ids('/api/study-resources'));
        $this->assertGreaterThanOrEqual(3, count($this->ids('/api/calendar-events')));

        // --- Estudiante de 4-B: ningún examen (el activo es de 4-A), solo lo enviado a 4-B
        $this->como("estudiante5{$dominio}");
        $this->assertSame([], $this->ids('/api/exams'));
        $this->assertSame(['Introducción a Álgebra'], $this->nombres('/api/study-resources', 'title'));
        $this->assertSame(['Recordatorio - Tarea de Matemáticas'], $this->nombres('/api/calendar-events', 'title'));

        // --- Admin: todo el centro
        $this->como("admin{$dominio}");
        $this->assertCount(5, $this->ids('/api/students', 'user_id'));
        $this->assertCount(2, $this->ids('/api/exams'));
        $this->assertCount(3, $this->ids('/api/subjects'));
    }

    public function test_las_cuentas_de_core_inician_sesion(): void
    {
        $this->sembrarCore();

        foreach (['admin', 'profesor1', 'profesor2', 'estudiante1', 'estudiante5'] as $local) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/auth/login', [
                'email' => "{$local}@neoeducore.edu.co", 'password' => CoreTablesSeeder::CLAVE,
            ])->assertOk();
        }
    }

    /* ============================================================
     | TallerAulaSeeder (el aula de 28 alumnos para el taller)
     ============================================================ */

    private function sembrarTaller(): string
    {
        (new TallerAulaSeeder())->run();

        return Institution::where('code', 'ENE-001')->firstOrFail()->id;
    }

    public function test_los_datos_del_taller_cumplen_las_reglas_del_modelo(): void
    {
        $this->assertInvariantes($this->sembrarTaller());
    }

    public function test_cada_rol_ve_lo_suyo_en_el_taller(): void
    {
        $this->sembrarTaller();
        $dominio = '@nuevaesperanza.ed.cr';

        // Rodrigo (Matemáticas y Ciencias de 4-A): los 28 de 4-A, ninguno de 4-B
        $this->como("rodrigo.pineda{$dominio}");
        $this->assertSame(28, $this->total('/api/students'));
        $this->assertCount(1, $this->ids('/api/groups'));

        // Lucía (Español en 4-A y 4-B): 34 alumnos y dos aulas
        $this->como("lucia.vindas{$dominio}");
        $this->assertSame(34, $this->total('/api/students'));
        $this->assertCount(2, $this->ids('/api/groups'));

        // Andrés, sin asignación: no ve a nadie ni ninguna materia
        $this->como("andres.calvo{$dominio}");
        $this->assertSame([], $this->ids('/api/students', 'user_id'));
        $this->assertSame([], $this->ids('/api/subjects'));
        $this->assertSame([], $this->ids('/api/groups'));

        // Alumna de 4-A: el examen, los 6 recursos y los avisos de su aula
        $this->como("ariana.solis{$dominio}");
        $this->assertCount(1, $this->ids('/api/exams'));
        $this->assertCount(6, $this->ids('/api/study-resources'));
        $this->assertCount(3, $this->ids('/api/calendar-events'));

        // Alumna de 4-B: ningún examen; solo el recurso de Español y la reunión de su aula
        $this->como("beatriz.cordero{$dominio}");
        $this->assertSame([], $this->ids('/api/exams'));
        $this->assertSame(['Comprensión de lectura: cuentos cortos'], $this->nombres('/api/study-resources', 'title'));
        $this->assertSame(['Reunión de padres y madres'], $this->nombres('/api/calendar-events', 'title'));
    }

    public function test_el_taller_conserva_al_superadmin_y_es_reproducible(): void
    {
        $super = User::factory()->superAdmin()->create(['institution_id' => null, 'status' => 'active']);

        $this->sembrarTaller();
        $primera = DB::table('students')->count();
        $this->sembrarTaller();

        $this->assertDatabaseHas('users', ['id' => $super->id]);
        $this->assertSame($primera, DB::table('students')->count(), 'Dos ejecuciones deben dar el mismo aula.');
    }

    /* ============================================================
     | LoadTestSeeder (escenario de la prueba de carga con k6)
     ============================================================ */

    public function test_los_datos_de_carga_cumplen_las_reglas_y_el_docente_ve_a_su_aula(): void
    {
        // Mismo criterio que en Core: el centro se borra CON sus cuentas.
        $anterior = Institution::where('code', LoadTestSeeder::CODIGO_INSTITUCION)->first();
        if ($anterior) {
            User::where('institution_id', $anterior->id)->delete();
            $anterior->delete();
        }
        User::where('email', 'like', '%@carga.test')->delete();

        // El seeder lee su tamaño de ESTUDIANTES y PREGUNTAS: aquí, uno pequeño.
        foreach (['ESTUDIANTES' => '6', 'PREGUNTAS' => '3'] as $clave => $valor) {
            putenv("{$clave}={$valor}");
            $_ENV[$clave] = $_SERVER[$clave] = $valor;
        }

        try {
            (new LoadTestSeeder())->run();
        } finally {
            foreach (['ESTUDIANTES', 'PREGUNTAS'] as $clave) {
                putenv($clave);
                unset($_ENV[$clave], $_SERVER[$clave]);
            }
        }

        $centro = Institution::where('code', LoadTestSeeder::CODIGO_INSTITUCION)->firstOrFail()->id;
        $this->assertInvariantes($centro);

        // El docente de la prueba ve a SU aula entera (sin asignación no vería a nadie).
        $this->como('teacher@carga.test');
        $this->assertSame(6, $this->total('/api/students'));

        // Y un alumno ve el examen de carga, que es lo que k6 va a empezar.
        $this->como('alumno1@carga.test');
        $this->assertSame(['Examen de carga'], $this->nombres('/api/exams', 'title'));
    }

    /* ============================================================
     | Invariantes del modelo (valen para cualquier seeder)
     ============================================================ */

    private function nombres(string $ruta, string $campo = 'name'): array
    {
        return $this->ids($ruta, $campo);
    }

    private function assertInvariantes(string $centro): void
    {
        $min = (int) config('academic.grade_min');
        $max = (int) config('academic.grade_max');
        $maxDuracion = (int) (Institution::findOrFail($centro)->settings['max_exam_duration']
            ?? Institution::$defaultSettings['max_exam_duration']);

        $donde = fn (string $tabla) => DB::table($tabla)->where('institution_id', $centro);

        // El centro y las cuentas están activos: si no, nadie podría entrar.
        $this->assertTrue((bool) Institution::findOrFail($centro)->is_active);
        $this->assertSame(0, $donde('users')->where('status', '!=', 'active')->count(), 'Cuentas no activas: nadie podría entrar.');

        // 1. Grados dentro del rango del sistema (1.º–6.º)
        foreach (['groups', 'students', 'exams'] as $tabla) {
            $this->assertSame(
                0, $donde($tabla)->where(fn ($q) => $q->where('grade', '<', $min)->orWhere('grade', '>', $max))->count(),
                "{$tabla}: hay grados fuera de {$min}–{$max}."
            );
        }
        $this->assertSame(0, $donde('study_resources')->where(fn ($q) => $q
            ->where('grade_min', '<', $min)->orWhere('grade_max', '>', $max))->count(), 'Recursos con grados fuera de rango.');

        // 2. Cada alumno está matriculado en UN aula activa, y su ficha coincide con ella
        foreach ($donde('students')->get() as $alumno) {
            $aulas = $donde('group_students')->where('student_user_id', $alumno->user_id)->whereNull('left_at')->pluck('group_id');
            $this->assertCount(1, $aulas, 'Un alumno debe estar en exactamente un aula activa.');

            $aula = DB::table('groups')->find($aulas[0]);
            $this->assertSame([$aula->grade, $aula->section, $aula->group_code], [$alumno->grade, $alumno->section, $alumno->group_code],
                'La ficha del alumno no coincide con su aula.');
        }

        // 3. El contador de cada aula es el número real de matriculados
        foreach ($donde('groups')->get() as $aula) {
            $this->assertSame(
                (int) $aula->student_count,
                $donde('group_students')->where('group_id', $aula->id)->whereNull('left_at')->count(),
                "El contador del aula {$aula->name} no coincide."
            );
        }

        // 4. Cada docente da clase en un aula por una ASIGNACIÓN (la fuente de todo su alcance)
        $asignado = fn (string $docente, string $aula, ?string $materia = null) => $donde('teacher_assignments')
            ->where('teacher_user_id', $docente)->where('group_id', $aula)
            ->when($materia, fn ($q) => $q->where('subject_id', $materia))->exists();

        // 5. Exámenes: solo a aulas y materias del autor, y dentro del máximo del director
        foreach ($donde('exams')->get() as $examen) {
            $this->assertLessThanOrEqual($maxDuracion, $examen->duration_minutes, "«{$examen->title}» pasa el máximo de {$maxDuracion} min.");
            $this->assertNotNull($examen->created_by_teacher_id);

            foreach ($donde('exam_targets')->where('exam_id', $examen->id)->pluck('group_id') as $aula) {
                $this->assertTrue(
                    $asignado($examen->created_by_teacher_id, $aula, $examen->subject_id),
                    "«{$examen->title}» va a un aula/materia donde su autor no está asignado."
                );
            }

            // Intentos dentro del máximo, y de alumnos a quienes el examen les llega
            foreach ($donde('exam_attempts')->where('exam_id', $examen->id)->get() as $intento) {
                $this->assertLessThanOrEqual($examen->max_attempts, $intento->attempt_number);
                $this->assertTrue(
                    $donde('group_students')->where('student_user_id', $intento->student_user_id)
                        ->whereIn('group_id', $donde('exam_targets')->where('exam_id', $examen->id)->pluck('group_id'))->exists(),
                    'Un intento de un alumno al que el examen no le llega.'
                );
            }
        }

        // 6. Preguntas: opción múltiple con 4 opciones y exactamente 1 correcta
        foreach ($donde('questions')->where('question_type', 'multiple_choice')->get() as $pregunta) {
            $opciones = $donde('question_options')->where('question_id', $pregunta->id);
            $this->assertSame(4, (clone $opciones)->count(), 'Opción múltiple debe tener 4 opciones.');
            $this->assertSame(1, (clone $opciones)->where('is_correct', true)->count(), 'Debe haber exactamente 1 opción correcta.');
        }

        // 7. Recursos: los crea un docente y los envía a aulas donde da esa materia
        foreach ($donde('study_resources')->get() as $recurso) {
            $aulas = $donde('study_resource_groups')->where('study_resource_id', $recurso->id)->pluck('group_id');
            $this->assertNotEmpty($aulas, "«{$recurso->title}» no está enviado a ningún aula: ningún estudiante lo vería.");

            foreach ($aulas as $aula) {
                $this->assertTrue(
                    $asignado($recurso->created_by, $aula, $recurso->subject_id),
                    "«{$recurso->title}» va a un aula/materia donde su autor no está asignado."
                );
            }
        }

        // 8. Avisos: siempre con aula, de un docente asignado a ella
        foreach ($donde('calendar_events')->get() as $evento) {
            $this->assertNotNull($evento->group_id, "«{$evento->title}» no tiene aula.");
            $this->assertTrue($asignado($evento->created_by, $evento->group_id), "«{$evento->title}» lo pone un docente no asignado al aula.");
        }

        // 9. Cada alumno cursa solo materias que se dan en su aula
        foreach ($donde('student_subjects')->get() as $inscripcion) {
            $aula = $donde('group_students')->where('student_user_id', $inscripcion->student_user_id)->whereNull('left_at')->value('group_id');
            $this->assertTrue(
                $donde('teacher_assignments')->where('group_id', $aula)->where('subject_id', $inscripcion->subject_id)->exists(),
                'Un alumno cursa una materia que no tiene docente en su aula.'
            );
        }

        // 10. Recomendaciones con un tipo válido del enum
        $validos = ['strength', 'weakness', 'resource', 'action'];
        $this->assertSame(0, $donde('ai_recommendations')->whereNotIn('recommendation_type', $validos)->count());
    }
}
