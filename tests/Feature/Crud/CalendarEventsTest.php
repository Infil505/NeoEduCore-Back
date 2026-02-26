<?php

namespace Tests\Feature\Crud;

use App\Models\Academic\CalendarEvent;
use App\Models\Exams\Exam;
use App\Models\Academic\Group;
use App\Models\Admin\Institution;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class CalendarEventsTest extends TestCase
{
    use ApiAuth;

    public function test_list_calendar_events(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        CalendarEvent::factory()->create([
            'institution_id' => $institution->id,
            'created_by' => $teacher->id,
        ]);

        $res = $this->getJson('/api/calendar-events');

        $res->assertOk();
    }

    public function test_create_calendar_event(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $group = Group::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->postJson('/api/calendar-events', [
            'title' => 'Examen Final',
            'description' => 'Examen final de matemáticas',
            'start_at' => now()->addDays(10),
            'end_at' => now()->addDays(10)->addHours(2),
            'event_type' => 'exam',
            'group_id' => $group->id,
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Examen Final',
            'institution_id' => $institution->id,
        ]);
    }

    public function test_show_calendar_event(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $event = CalendarEvent::factory()->create([
            'institution_id' => $institution->id,
            'created_by' => $teacher->id,
        ]);

        $res = $this->getJson("/api/calendar-events/{$event->id}");

        $res->assertOk();
    }

    public function test_update_calendar_event(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $event = CalendarEvent::factory()->create([
            'institution_id' => $institution->id,
            'created_by' => $teacher->id,
        ]);

        $res = $this->putJson("/api/calendar-events/{$event->id}", [
            'title' => 'Evento Actualizado',
            'event_type' => 'reminder',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('calendar_events', [
            'id' => $event->id,
            'title' => 'Evento Actualizado',
        ]);
    }

    public function test_delete_calendar_event(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $event = CalendarEvent::factory()->create([
            'institution_id' => $institution->id,
            'created_by' => $teacher->id,
        ]);

        $res = $this->deleteJson("/api/calendar-events/{$event->id}");

        $res->assertNoContent();
    }
}
