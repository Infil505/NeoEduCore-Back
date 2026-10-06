<?php

namespace Tests\Feature\Security;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Matriz de acciones por rol (05/10/2026): una sola tabla con quién puede hacer
 * qué, para ver el estado real de los permisos de un vistazo.
 *
 * Comprueba el **lado negativo**: todo rol que no figure como permitido recibe
 * 403. Es la parte fiable en una prueba genérica, porque el middleware
 * `role:` corta antes de que el controlador valide nada. El lado positivo (que
 * quien sí puede, puede, y solo con lo suyo) lo cubren los tests de cada
 * funcionalidad: `AlcancePorRolTest`, `AlcanceDocentePorMateriaTest`,
 * `ExamenesMateriasYDuracionTest` y `RecursosYEventosPorAulaTest`.
 *
 * Si se añade una ruta con restricción de rol, tiene que aparecer aquí.
 */
class MatrizDeRolesTest extends TestCase
{
    use ApiAuth;

    private const ADMIN = 'admin';
    private const DOCENTE = 'teacher';
    private const ALUMNO = 'student';
    private const SUPER = 'superadmin';

    private Institution $centro;
    /** @var array<string,User> */
    private array $usuarios = [];
    private Group $grupo;
    private Exam $examen;
    private Subject $materia;
    private StudyResource $recurso;
    private CalendarEvent $evento;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro = Institution::factory()->create();

        $this->usuarios[self::ADMIN]   = User::factory()->admin()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        $this->usuarios[self::DOCENTE] = User::factory()->teacher()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        $this->usuarios[self::ALUMNO]  = User::factory()->student()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $this->usuarios[self::ALUMNO]->id, 'institution_id' => $this->centro->id]);
        $this->usuarios[self::SUPER]   = User::factory()->superAdmin()->create(['institution_id' => null, 'status' => 'active']);

        // Filas reales para las rutas con binding de modelo: el binding resuelve
        // antes que el middleware de rol y un id inventado daría 404 en vez de 403.
        $this->grupo   = Group::factory()->create(['institution_id' => $this->centro->id]);
        $this->materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $this->examen  = Exam::factory()->create([
            'institution_id' => $this->centro->id,
            'created_by_teacher_id' => $this->usuarios[self::DOCENTE]->id,
        ]);
        $this->recurso = StudyResource::factory()->create([
            'institution_id' => $this->centro->id, 'created_by' => $this->usuarios[self::DOCENTE]->id,
        ]);
        $this->evento = CalendarEvent::factory()->create([
            'institution_id' => $this->centro->id, 'created_by' => $this->usuarios[self::DOCENTE]->id,
            'group_id' => $this->grupo->id,
        ]);
    }

    /**
     * [método, ruta, roles permitidos, ¿lleva binding de modelo?]
     *
     * Las rutas con binding no se prueban con el superadmin: no tiene institución
     * y el binding de modelos con tenant no resuelve sin ella.
     *
     * @return array<int,array{0:string,1:string,2:array<int,string>,3:bool}>
     */
    private function matriz(): array
    {
        $g = $this->grupo->id;
        $e = $this->examen->id;
        $s = $this->materia->id;
        $r = $this->recurso->id;
        $v = $this->evento->id;
        $u = $this->usuarios[self::ALUMNO]->id;   // nadie lo borra: los roles no permitidos reciben 403
        $i = $this->centro->id;
        $uuid = '00000000-0000-4000-8000-000000000000';

        [$A, $D, $E, $S] = [self::ADMIN, self::DOCENTE, self::ALUMNO, self::SUPER];

        return [
            // --- Estructura del centro: solo el administrador ---
            ['POST',   '/api/groups',                        [$A],       false],
            ['PUT',    "/api/groups/{$g}",                   [$A],       true],
            ['DELETE', "/api/groups/{$g}",                   [$A],       true],
            ['POST',   "/api/groups/{$g}/students",          [$A],       true],
            ['DELETE', "/api/groups/{$g}/students",          [$A],       true],
            ['POST',   '/api/subjects',                      [$A],       false],
            ['DELETE', "/api/subjects/{$s}",                 [$A],       true],
            ['POST',   '/api/teacher-assignments',           [$A],       false],
            ['POST',   '/api/bulk/reassign-group',           [$A],       false],
            ['POST',   '/api/students/bulk-upload',          [$A],       false],
            ['POST',   '/api/register',                      [$A],       false],
            ['PUT',    '/api/system/config',                 [$A],       false],
            ['PATCH',  "/api/students/{$uuid}/status",       [$A],       false],
            ['DELETE', "/api/users/{$u}",                    [$A],       true],

            // --- Actividades del docente: solo el docente las crea ---
            ['POST',   '/api/study-resources',               [$D],       false],
            ['POST',   '/api/calendar-events',               [$D],       false],

            // --- Docente y administrador ---
            ['GET',    '/api/system/config',                 [$A, $D],   false],
            ['POST',   '/api/exams',                         [$A, $D],   false],
            ['PATCH',  "/api/exams/{$e}/status",             [$A, $D],   true],
            ['DELETE', "/api/exams/{$e}",                    [$A, $D],   true],
            ['PUT',    "/api/study-resources/{$r}",          [$A, $D],   true],
            ['DELETE', "/api/calendar-events/{$v}",          [$A, $D],   true],
            ['GET',    '/api/students',                      [$A, $D],   false],
            ['PUT',    "/api/students/{$uuid}",              [$A, $D],   false],
            ['POST',   '/api/student-progress',              [$A, $D],   false],
            ['POST',   '/api/ai/generate',                   [$A, $D],   false],
            ['GET',    '/api/reports/topics',                [$A, $D],   false],
            ['GET',    '/api/analytics/institution',         [$A, $D],   false],

            // --- Lectura compartida: todos menos el superadmin ---
            ['GET',    '/api/exams',                         [$A, $D, $E], false],
            ['GET',    '/api/subjects',                      [$A, $D, $E], false],
            ['GET',    '/api/study-resources',               [$A, $D, $E], false],
            ['GET',    '/api/calendar-events',               [$A, $D, $E], false],

            // --- Solo el estudiante ---
            ['POST',   "/api/exams/{$e}/attempts/start",     [$E],       true],
            ['GET',    '/api/students/me',                   [$E],       false],
            ['GET',    '/api/student-progress/me',           [$E],       false],
            ['POST',   '/api/ai/tutor/chat',                 [$E],       false],
            ['GET',    '/api/ai/tutor/sessions',             [$E],       false],

            // --- Solo el superadmin (externo a las instituciones) ---
            ['GET',    '/api/institutions',                  [$S],       false],
            ['POST',   '/api/institutions',                  [$S],       false],
            ['POST',   "/api/institutions/{$i}/admins",      [$S],       false],
            ['GET',    '/api/institution-admins',            [$S],       false],
            ['GET',    '/api/platform/ai-tutor-metrics',     [$S],       false],
        ];
    }

    public function test_cada_rol_recibe_403_en_lo_que_no_le_corresponde(): void
    {
        $fallos = [];

        foreach ($this->matriz() as [$metodo, $ruta, $permitidos, $conBinding]) {
            foreach ([self::ADMIN, self::DOCENTE, self::ALUMNO, self::SUPER] as $rol) {
                if (in_array($rol, $permitidos, true)) {
                    continue;
                }
                if ($rol === self::SUPER && $conBinding) {
                    continue;
                }

                Sanctum::actingAs($this->usuarios[$rol]);
                $estado = $this->json($metodo, $ruta, [])->getStatusCode();

                if ($estado !== 403) {
                    $fallos[] = "{$rol} → {$metodo} {$ruta} dio {$estado} (esperado 403)";
                }
            }
        }

        $this->assertSame([], $fallos, "Rutas abiertas a un rol que no debería entrar:\n" . implode("\n", $fallos));
    }

    /**
     * Sin sesión, ninguna ruta de la matriz responde algo distinto de 401: la
     * matriz no puede dejar una puerta sin autenticación.
     */
    public function test_sin_sesion_toda_la_matriz_da_401(): void
    {
        $fallos = [];

        foreach ($this->matriz() as [$metodo, $ruta]) {
            $estado = $this->json($metodo, $ruta, [])->getStatusCode();
            if ($estado !== 401) {
                $fallos[] = "{$metodo} {$ruta} dio {$estado} (esperado 401)";
            }
        }

        $this->assertSame([], $fallos, implode("\n", $fallos));
    }

    /**
     * El lado positivo de lo nuevo, en una línea por rol: quien puede, puede.
     * (El detalle de alcance está en los tests de cada funcionalidad.)
     */
    public function test_cada_rol_entra_en_lo_suyo(): void
    {
        Sanctum::actingAs($this->usuarios[self::ADMIN]);
        $this->getJson('/api/groups')->assertOk();
        $this->getJson('/api/system/config')->assertOk();

        Sanctum::actingAs($this->usuarios[self::DOCENTE]);
        $this->getJson('/api/exams')->assertOk();
        $this->getJson('/api/study-resources')->assertOk();

        Sanctum::actingAs($this->usuarios[self::ALUMNO]);
        $this->getJson('/api/students/me')->assertOk();
        $this->getJson('/api/calendar-events')->assertOk();

        Sanctum::actingAs($this->usuarios[self::SUPER]);
        $this->getJson('/api/institutions')->assertOk();
    }
}
