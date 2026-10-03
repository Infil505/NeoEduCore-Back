<?php

namespace Tests\Feature\Security;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use App\Services\Academic\BulkReassignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Qué separa de verdad a una institución de otra.
 *
 * **No es el RLS.** Las 26 tablas lo tienen activado y **cero políticas**, y la
 * aplicación se conecta con un rol que además lo evita (`rolbypassrls`). Ese RLS
 * es un candado contra los demás roles de Supabase —`anon` y `authenticated` no
 * sacan una fila por PostgREST, que es la amenaza realista— pero no compara
 * `institution_id` con nada. El aislamiento por institución es cosa de
 * `TenantScoped` y de los controladores, y por eso vive aquí, en tests.
 *
 * Dos bloques:
 *
 * 1. **El guardia del trait**, que decide qué hacer cuando se consulta un modelo
 *    sin tenant: en HTTP lanzar, en consola dejar pasar.
 * 2. **Las escrituras crudas**, que se acotaban por deducción y ahora por
 *    `WHERE`.
 */
class AislamientoMultitenantTest extends TestCase
{
    use ApiAuth;

    protected function tearDown(): void
    {
        app()->forgetInstance('contexto_http');
        app()->forgetInstance('tenant_id');

        parent::tearDown();
    }

    /* =========================================================
     | 1. El guardia de TenantScoped
     ========================================================= */

    /**
     * En HTTP, un modelo acotado consultado sin tenant **lanza**. Si devolviera
     * la consulta sin filtrar estaría entregando las filas de todas las
     * instituciones, que es exactamente el fallo que el trait existe para
     * evitar.
     */
    public function test_en_http_consultar_sin_tenant_lanza_en_vez_de_devolver_de_mas(): void
    {
        app()->forgetInstance('tenant_id');
        app()->instance('contexto_http', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sin contexto de tenant/');

        Student::query()->get();
    }

    /**
     * En consola —migraciones, seeders, comandos, worker de cola— no hay tenant
     * y es lo normal: el job de recomendaciones, por ejemplo, tiene que
     * encontrar el intento **antes** de saber a qué centro pertenece.
     */
    public function test_en_consola_la_misma_consulta_no_lanza(): void
    {
        app()->forgetInstance('tenant_id');
        app()->forgetInstance('contexto_http');

        $this->assertIsIterable(Student::query()->limit(1)->get());
    }

    /**
     * La marca la pone el middleware global, no se deduce de `PHP_SAPI`.
     *
     * Este test es el que sostiene todo lo anterior: con la guarda antigua
     * —`app()->runningInConsole()`— bastaba con arrancar Octane con Swoole o
     * RoadRunner, cuyo SAPI **es** `cli`, para que el trait dejara de lanzar en
     * plena petición HTTP y empezara a devolver datos de todas las instituciones
     * sin un solo error.
     */
    public function test_toda_peticion_http_queda_marcada_como_tal(): void
    {
        app()->forgetInstance('contexto_http');

        $this->getJson('/api/system/config');

        $this->assertTrue(
            app()->bound('contexto_http'),
            'MarcaContextoHttp debe correr en el stack global, no solo en el grupo api.'
        );
    }

    /* =========================================================
     | 2. Escrituras crudas acotadas por WHERE, no por deducción
     ========================================================= */

    /**
     * La reasignación masiva cierra matrículas con un `UPDATE` sobre
     * `group_students` acotado por la lista de alumnos. Esa lista sale de
     * `Student`, que es TenantScoped, así que **en datos coherentes no hay forma
     * de tocar otro centro** — y por eso este test siembra una fila incoherente
     * a propósito: una matrícula de un alumno del centro A que dice pertenecer
     * al centro B.
     *
     * El modelo de datos no debería permitirla. La cuestión es qué pasa si
     * aparece igual —una carga mal hecha, un arreglo a mano en producción, un
     * bug futuro—, y la respuesta debe ser que la consulta no la toque, en vez
     * de que la toque porque nadie se lo impide. Es defensa en profundidad: la
     * primera línea sigue siendo el scope.
     */
    public function test_la_reasignacion_masiva_ignora_una_matricula_de_otra_institucion(): void
    {
        [$centroA, $aulaA, $alumnoA] = $this->centroConAula('4-A');
        [$centroB, $aulaB]           = $this->centroConAula('4-B');

        // La fila incoherente: el alumno es del centro A (así que pasa el filtro
        // de Student), pero esta matrícula suya es del centro B.
        DB::table('group_students')->insert([
            'id'              => (string) Str::uuid(),
            'institution_id'  => $centroB->id,
            'group_id'        => $aulaB->id,
            'student_user_id' => $alumnoA->id,
            'joined_at'       => now()->subDays(10),
            'left_at'         => null,
        ]);

        app()->instance('tenant_id', $centroA->id);

        $destino = Group::factory()->create([
            'institution_id' => $centroA->id,
            'name'           => '5-A',
        ]);

        app(BulkReassignmentService::class)->reassignGroup($centroA->id, [$alumnoA->id], $destino);

        // La matrícula del centro ajeno sigue abierta: no se cerró de rebote.
        $this->assertDatabaseHas('group_students', [
            'student_user_id' => $alumnoA->id,
            'group_id'        => $aulaB->id,
            'institution_id'  => $centroB->id,
            'left_at'         => null,
        ]);

        // La del centro propio sí se movió al destino.
        $this->assertDatabaseHas('group_students', [
            'student_user_id' => $alumnoA->id,
            'group_id'        => $destino->id,
            'left_at'         => null,
        ]);
    }

    /**
     * Lo mismo para `reassignSubjects` en modo `replace`, que es el `DELETE` con
     * menos red de seguridad del sistema: borra todo lo que no esté en el plan.
     */
    public function test_la_reasignacion_de_materias_ignora_una_inscripcion_de_otra_institucion(): void
    {
        [$centroA, , $alumnoA] = $this->centroConAula('4-A');
        [$centroB]             = $this->centroConAula('4-B');

        $materiaB = Subject::factory()->create(['institution_id' => $centroB->id]);

        // Inscripción incoherente: alumno del centro A, fila del centro B.
        DB::table('student_subjects')->insert([
            'id'              => (string) Str::uuid(),
            'institution_id'  => $centroB->id,
            'student_user_id' => $alumnoA->id,
            'subject_id'      => $materiaB->id,
            'enrolled_at'     => now(),
        ]);

        app()->instance('tenant_id', $centroA->id);

        $materiaA = Subject::factory()->create(['institution_id' => $centroA->id]);

        app(BulkReassignmentService::class)->reassignSubjects(
            $centroA->id,
            [$alumnoA->id],
            [$materiaA->id],
            'replace'
        );

        $this->assertDatabaseHas('student_subjects', [
            'student_user_id' => $alumnoA->id,
            'subject_id'      => $materiaB->id,
        ]);
    }

    /**
     * `Exam::scopeVisibleTo()` resuelve los exámenes de un alumno saltando por
     * `group_students`. La subconsulta no miraba institución.
     */
    public function test_los_examenes_visibles_de_un_alumno_no_cruzan_de_centro(): void
    {
        [$centroA, $aulaA, $alumnoA] = $this->centroConAula('4-A');

        app()->instance('tenant_id', $centroA->id);

        $examen = Exam::factory()->create([
            'institution_id' => $centroA->id,
            'subject_id'     => Subject::factory()->create(['institution_id' => $centroA->id])->id,
            'status'         => 'active',
        ]);

        DB::table('exam_targets')->insert([
            'id'             => (string) Str::uuid(),
            'institution_id' => $centroA->id,
            'exam_id'        => $examen->id,
            'group_id'       => $aulaA->id,
        ]);

        $visibles = Exam::query()->visibleTo($alumnoA)->pluck('id')->all();

        $this->assertSame([$examen->id], $visibles);
    }

    /* =========================
     | Apoyo
     ========================= */

    /**
     * Un centro con su aula y un alumno matriculado.
     *
     * @return array{0: Institution, 1: Group, 2: User}
     */
    private function centroConAula(string $nombreAula): array
    {
        $institution = Institution::factory()->create();

        app()->instance('tenant_id', $institution->id);

        $aula = Group::factory()->create([
            'institution_id' => $institution->id,
            'name'           => $nombreAula,
        ]);

        $alumno = User::factory()->student()->create(['institution_id' => $institution->id]);

        Student::factory()->create([
            'user_id'        => $alumno->id,
            'institution_id' => $institution->id,
            'grade'          => 4,
        ]);

        DB::table('group_students')->insert([
            'id'              => (string) Str::uuid(),
            'institution_id'  => $institution->id,
            'group_id'        => $aula->id,
            'student_user_id' => $alumno->id,
            'joined_at'       => now()->subDays(30),
            'left_at'         => null,
        ]);

        return [$institution, $aula, $alumno];
    }
}
