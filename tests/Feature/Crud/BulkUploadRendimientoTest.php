<?php

namespace Tests\Feature\Crud;

use App\Models\Academic\Group;
use App\Models\Admin\AccessToken;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Rendimiento de la carga masiva y del camino caliente de la API.
 *
 * Con la base remota cada consulta cuesta cientos de milisegundos, así que lo
 * que importa no es el tiempo del test sino el NÚMERO de consultas: tiene que
 * ser constante respecto al número de filas. Si alguien vuelve a meter una
 * consulta dentro del bucle, estos tests lo delatan sin necesidad de medir red.
 */
class BulkUploadRendimientoTest extends TestCase
{
    use ApiAuth;

    private const HEADER = 'full_name,email,user_id,student_code,aula,status,birth_date,parent_name,parent_email,adecuacion_type';

    private string $sufijo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sufijo = uniqid();
    }

    private function csv(int $n, int $desde = 1): string
    {
        $lineas = [self::HEADER];
        for ($i = $desde; $i < $desde + $n; $i++) {
            $lineas[] = "Alumno {$i},alumno{$i}{$this->sufijo}@ejemplo.com,,EST-{$i}{$this->sufijo},4A2026,active,,,,";
        }

        return implode("\n", $lineas) . "\n";
    }

    private function subir(string $csv, bool $dryRun = false)
    {
        $file = UploadedFile::fake()->createWithContent('estudiantes.csv', $csv);

        return $this->post('/api/students/bulk-upload', ['file' => $file] + ($dryRun ? ['dry_run' => true] : []));
    }

    /** Consultas que ejecuta la subida (sin contar las del login del test). */
    private function consultas(callable $subida): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $subida();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_las_consultas_no_crecen_con_el_numero_de_filas(): void
    {
        Queue::fake(); // el correo de alta lo manda el worker, no la petición

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        Group::factory()->create(['institution_id' => $institution->id, 'group_code' => '4A2026', 'grade' => 4, 'section' => 'A']);

        $pocas  = $this->consultas(fn () => $this->subir($this->csv(5))->assertOk()->assertJson(['created' => 5]));
        $muchas = $this->consultas(fn () => $this->subir($this->csv(120, 100))->assertOk()->assertJson(['created' => 120]));

        // 120 filas nuevas cuestan lo mismo que 5 (pueden variar unas pocas por
        // el cache o los trabajos encolados, nunca una por fila).
        $this->assertLessThanOrEqual($pocas + 3, $muchas, "5 filas: {$pocas} consultas; 120 filas: {$muchas}.");
        $this->assertLessThan(40, $muchas);
    }

    public function test_recargar_el_mismo_archivo_no_escribe_en_las_fichas(): void
    {
        Queue::fake(); // el correo de alta lo manda el worker, no la petición

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        Group::factory()->create(['institution_id' => $institution->id, 'group_code' => '4A2026', 'grade' => 4, 'section' => 'A']);

        $csv = $this->csv(30);
        $this->subir($csv)->assertOk()->assertJson(['created' => 30]);

        $escrituras = 0;
        DB::listen(function ($q) use (&$escrituras) {
            if (preg_match('/^\s*(update|insert|delete)/i', $q->sql)) {
                $escrituras++;
            }
        });

        $this->subir($csv)->assertOk()->assertJson(['created' => 0, 'updated' => 30, 'matriculados' => 0, 'reasignados' => 0]);

        // Solo el recuento de aulas (ninguna tocada: ni una escritura de alumnos).
        $this->assertLessThanOrEqual(1, $escrituras, "Se hicieron {$escrituras} escrituras al recargar un archivo idéntico.");
    }

    public function test_la_vista_previa_no_escribe_nada(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        Group::factory()->create(['institution_id' => $institution->id, 'group_code' => '4A2026', 'grade' => 4, 'section' => 'A']);

        $usuariosAntes = User::count();
        $escrituras    = 0;
        DB::listen(function ($q) use (&$escrituras) {
            if (preg_match('/^\s*(update|insert|delete)/i', $q->sql)) {
                $escrituras++;
            }
        });

        $this->subir($this->csv(10), dryRun: true)
            ->assertOk()
            ->assertJson(['dry_run' => true, 'valid' => 10, 'invalid' => 0]);

        $this->assertSame($usuariosAntes, User::count());
        $this->assertSame(0, Student::count());
        $this->assertSame(0, $escrituras, 'La vista previa escribió en la base.');
    }

    public function test_el_codigo_de_otro_estudiante_se_rechaza_contra_el_mapa_en_memoria(): void
    {
        Queue::fake(); // el correo de alta lo manda el worker, no la petición

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        Group::factory()->create(['institution_id' => $institution->id, 'group_code' => '4A2026', 'grade' => 4, 'section' => 'A']);

        $s = $this->sufijo;
        $this->subir(self::HEADER . "
"
            . "Ana,ana{$s}@ejemplo.com,,EST-1{$s},4A2026,active,,,,
"
            . "Beto,beto{$s}@ejemplo.com,,EST-2{$s},4A2026,active,,,,
")->assertOk()->assertJson(['created' => 2]);

        // Beto intenta quedarse con el código de Ana.
        $res = $this->subir(self::HEADER . "
"
            . "Beto,beto{$s}@ejemplo.com,,EST-1{$s},4A2026,active,,,,
")->assertOk();

        $res->assertJson(['created' => 0, 'updated' => 0, 'skipped' => 1]);
        $this->assertStringContainsString('ya está en uso', $res->json('errors.0'));
        $this->assertDatabaseHas('students', ['student_code' => "EST-2{$s}"]);
    }

    public function test_el_mismo_codigo_dos_veces_en_el_archivo_no_crea_una_segunda_cuenta(): void
    {
        Queue::fake();

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        Group::factory()->create(['institution_id' => $institution->id, 'group_code' => '4A2026', 'grade' => 4, 'section' => 'A']);

        $s = $this->sufijo;
        $this->subir(self::HEADER . "
"
            . "Ana,ana{$s}@ejemplo.com,,EST-1{$s},4A2026,active,,,,
"
            . "Beto,beto{$s}@ejemplo.com,,EST-1{$s},4A2026,active,,,,
")
            ->assertOk()
            ->assertJson(['created' => 1, 'users_created' => 1]);

        $this->assertDatabaseMissing('users', ['email' => "beto{$s}@ejemplo.com"]);
        $this->assertSame(1, Student::where('student_code', "EST-1{$s}")->count());
    }

    public function test_un_traslado_cierra_la_matricula_vieja_y_abre_la_nueva(): void
    {
        Queue::fake(); // el correo de alta lo manda el worker, no la petición

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $a = Group::factory()->create(['institution_id' => $institution->id, 'group_code' => '4A2026', 'grade' => 4, 'section' => 'A']);
        $b = Group::factory()->create(['institution_id' => $institution->id, 'group_code' => '5B2026', 'grade' => 5, 'section' => 'B']);

        $this->subir(self::HEADER . "\nAna,ana@ejemplo.com,,EST-1,4A2026,active,,,,\n")->assertOk();
        $ana = User::where('email', 'ana@ejemplo.com')->first();

        $this->subir(self::HEADER . "\nAna,ana@ejemplo.com,,EST-1,5B2026,active,,,,\n")
            ->assertOk()
            ->assertJson(['reasignados' => 1, 'matriculados' => 0]);

        $abiertas = DB::table('group_students')->where('student_user_id', $ana->id)->whereNull('left_at')->pluck('group_id');
        $this->assertSame([$b->id], $abiertas->all());
        $this->assertNotNull(DB::table('group_students')->where('student_user_id', $ana->id)->where('group_id', $a->id)->value('left_at'));
        $this->assertSame(1, (int) DB::table('groups')->where('id', $b->id)->value('student_count'));
        $this->assertSame(0, (int) DB::table('groups')->where('id', $a->id)->value('student_count'));
    }

    public function test_el_token_no_escribe_last_used_at_en_cada_peticion(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // Primera petición: el token nunca se usó, se anota.
        $this->withToken($token)->getJson('/api/system/config')->assertOk();
        $primera = AccessToken::query()->latest('id')->first()->last_used_at;
        $this->assertNotNull($primera);

        // Segunda, de inmediato: no hay UPDATE sobre personal_access_tokens.
        $updates = 0;
        DB::listen(function ($q) use (&$updates) {
            if (str_starts_with(strtolower(ltrim($q->sql)), 'update "personal_access_tokens"')) {
                $updates++;
            }
        });
        $this->withToken($token)->getJson('/api/system/config')->assertOk();
        $this->assertSame(0, $updates, 'Se escribió last_used_at en una petición consecutiva.');

        // Pasado el minuto vuelve a anotarse.
        DB::table('personal_access_tokens')->update(['last_used_at' => now()->subMinutes(2)]);
        $this->withToken($token)->getJson('/api/system/config')->assertOk();
        $this->assertSame(1, $updates);
    }
}
