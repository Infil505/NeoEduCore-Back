<?php

namespace Tests\Feature\Crud;

use App\Jobs\EnviarEnlaceDeAlta;
use App\Jobs\ProcesarCargaMasivaEstudiantes;
use App\Models\Academic\Group;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Notifications\CargaMasivaEstudiantes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * La carga masiva de estudiantes responde enseguida y trabaja en el worker.
 *
 * Procesar cientos de filas contra la base remota supera cualquier timeout de
 * navegador o proxy (el usuario veía «no se pudo cargar» con la carga ya a
 * medias), así que la petición solo recibe el archivo y devuelve 200 con un
 * `import_id`; el resultado llega como aviso y por `GET /bulk-upload/{id}`.
 */
class BulkUploadSegundoPlanoTest extends TestCase
{
    use ApiAuth;

    private const HEADER = 'full_name,email,user_id,student_code,aula';

    private Institution $institution;
    private string $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = uniqid();
        $this->institution = Institution::factory()->create();
        Group::factory()->create(['institution_id' => $this->institution->id, 'group_code' => '4A2026', 'grade' => 4, 'section' => 'A']);
    }

    private function csv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('e.csv', self::HEADER . "\n"
            . "Ana,ana{$this->s}@ejemplo.com,,A-{$this->s},4A2026\n"
            . "Beto,beto{$this->s}@ejemplo.com,,B-{$this->s},4A2026\n");
    }

    public function test_la_peticion_solo_encola_y_responde_200_con_el_id(): void
    {
        Queue::fake(); // nada corre: se ve lo que hace la PETICIÓN
        $admin = $this->signInAdmin(['institution_id' => $this->institution->id]);

        $res = $this->post('/api/students/bulk-upload', ['file' => $this->csv()]);

        $res->assertOk()->assertJson(['status' => 'queued', 'total_rows' => 2]);
        $this->assertNotEmpty($res->json('import_id'));
        Queue::assertPushed(ProcesarCargaMasivaEstudiantes::class, 1);

        // Todavía no se ha creado nadie: eso lo hace el worker.
        $this->assertDatabaseMissing('users', ['email' => "ana{$this->s}@ejemplo.com"]);

        // El aviso ya existe y se puede consultar mientras tanto.
        $this->getJson('/api/students/bulk-upload/' . $res->json('import_id'))
            ->assertOk()
            ->assertJson(['status' => 'queued', 'total_rows' => 2]);

        $this->assertSame(1, $admin->notifications()->where('type', CargaMasivaEstudiantes::TIPO)->count());
    }

    public function test_el_worker_termina_la_carga_y_deja_el_resultado_en_el_aviso(): void
    {
        Queue::fake([EnviarEnlaceDeAlta::class]); // el job de carga SÍ corre (cola sync)
        $admin = $this->signInAdmin(['institution_id' => $this->institution->id]);

        $res = $this->post('/api/students/bulk-upload', ['file' => $this->csv()])->assertOk();

        $this->getJson('/api/students/bulk-upload/' . $res->json('import_id'))
            ->assertOk()
            ->assertJson(['status' => 'done', 'created' => 2, 'skipped' => 0, 'matriculados' => 2]);

        $this->assertDatabaseHas('users', ['email' => "ana{$this->s}@ejemplo.com", 'status' => 'active', 'must_change_password' => true]);
        // Al terminar vuelve a quedar sin leer, para que la campana lo muestre.
        $this->assertNull($admin->notifications()->first()->read_at);
    }

    public function test_un_fallo_deja_el_aviso_en_failed_y_no_guarda_nada(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);
        Queue::fake();

        $res = $this->post('/api/students/bulk-upload', ['file' => $this->csv()])->assertOk();

        // Se ejecuta el job a mano con el tenant de otra institución inexistente.
        $job = Queue::pushed(ProcesarCargaMasivaEstudiantes::class)->first();
        $job->failed(new \RuntimeException('boom'));

        $this->getJson('/api/students/bulk-upload/' . $res->json('import_id'))
            ->assertOk()
            ->assertJson(['status' => 'failed']);
        $this->assertDatabaseMissing('users', ['email' => "ana{$this->s}@ejemplo.com"]);
    }

    public function test_solo_ve_el_estado_quien_subio_el_archivo(): void
    {
        Queue::fake();
        $this->signInAdmin(['institution_id' => $this->institution->id]);
        $id = $this->post('/api/students/bulk-upload', ['file' => $this->csv()])->json('import_id');

        // Otro administrador, incluso de la misma institución, recibe 404.
        $otro = User::factory()->admin()->create(['institution_id' => $this->institution->id, 'status' => 'active']);
        $this->actingAs($otro, 'sanctum')
            ->getJson("/api/students/bulk-upload/{$id}")
            ->assertNotFound();
    }

    public function test_los_errores_de_validacion_rapida_siguen_siendo_sincronos(): void
    {
        Queue::fake();
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $this->post('/api/students/bulk-upload', [
            'file' => UploadedFile::fake()->createWithContent('e.csv', "full_name,email\nAna,ana@ejemplo.com\n"),
        ], ['Accept' => 'application/json'])->assertStatus(422); // falta «Sección»

        Queue::assertNothingPushed();
    }

    public function test_un_id_que_no_es_uuid_es_404_sin_tocar_la_base(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $this->getJson('/api/students/bulk-upload/no-es-un-uuid')->assertNotFound();
    }
}
