<?php

namespace Tests\Feature\Crud;

use App\Models\Academic\StudyResource;
use App\Models\Admin\Institution;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class StudyResourcesTest extends TestCase
{
    use ApiAuth;

    public function test_list_study_resources(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        StudyResource::factory()->create([
            'institution_id' => $institution->id,
            'created_by' => $teacher->id,
        ]);

        $res = $this->getJson('/api/study-resources');

        $res->assertOk();
    }

    public function test_create_study_resource(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        // El recurso llega al aula que el admin le asignó (la única: va por defecto).
        $group = \App\Models\Academic\Group::factory()->create(['institution_id' => $institution->id]);
        $this->asignarDocente($teacher, $group->id, \App\Models\Academic\Subject::factory()->create(['institution_id' => $institution->id])->id);

        $res = $this->postJson('/api/study-resources', [
            'title' => 'Introducción a Álgebra',
            'description' => 'Video tutorial',
            'resource_type' => 'video',
            'url' => 'https://youtube.com/watch?v=example',
            'estimated_duration' => 30,
            'difficulty' => 'basic',
            'grade_min' => 9,
            'grade_max' => 11,
            'language' => 'es',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('study_resources', [
            'title' => 'Introducción a Álgebra',
            'institution_id' => $institution->id,
        ]);
    }

    /** El docente enlaza lo que quiera (no solo dominios educativos), pero siempre http(s). */
    public function test_docente_puede_enlazar_cualquier_dominio_pero_solo_http(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);
        $group = \App\Models\Academic\Group::factory()->create(['institution_id' => $institution->id]);
        $this->asignarDocente($teacher, $group->id, \App\Models\Academic\Subject::factory()->create(['institution_id' => $institution->id])->id);

        $recurso = fn (string $url) => $this->postJson('/api/study-resources', [
            'title' => 'Recurso libre', 'resource_type' => 'link', 'url' => $url,
        ]);

        $recurso('https://mi-sitio-cualquiera.example.org/clase')->assertCreated();

        foreach (['javascript:alert(1)', 'data:text/html;base64,PGgxPg==', 'ftp://example.org/a'] as $peligrosa) {
            $recurso($peligrosa)->assertStatus(422)->assertJsonValidationErrors('url');
        }
    }

    public function test_show_study_resource(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $resource = StudyResource::factory()->create([
            'institution_id' => $institution->id,
            'created_by' => $teacher->id,
        ]);

        $res = $this->getJson("/api/study-resources/{$resource->id}");

        $res->assertOk();
    }

    public function test_update_study_resource(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $resource = StudyResource::factory()->create([
            'institution_id' => $institution->id,
            'created_by' => $teacher->id,
        ]);

        $res = $this->putJson("/api/study-resources/{$resource->id}", [
            'title' => 'Recurso Actualizado',
            'difficulty' => 'advanced',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('study_resources', [
            'id' => $resource->id,
            'title' => 'Recurso Actualizado',
        ]);
    }

    public function test_delete_study_resource(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $resource = StudyResource::factory()->create([
            'institution_id' => $institution->id,
            'created_by' => $teacher->id,
        ]);

        $res = $this->deleteJson("/api/study-resources/{$resource->id}");

        $res->assertNoContent();
    }
}
