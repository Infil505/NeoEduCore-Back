<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CalendarEventController extends Controller
{
    /**
     * Listar eventos (con filtros)
     * Filtros: event_type, group_id, exam_id, from, to
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'event_type' => ['nullable', Rule::in(['exam', 'activity', 'reminder', 'meeting'])],
            'group_id'   => ['nullable', 'uuid'],
            'exam_id'    => ['nullable', 'uuid'],
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = CalendarEvent::query()
            ->with(['creator', 'group', 'exam'])
            ->orderBy('start_at');

        if (!empty($data['event_type'])) {
            $query->where('event_type', $data['event_type']);
        }
        if (!empty($data['group_id'])) {
            $query->where('group_id', $data['group_id']);
        }
        if (!empty($data['exam_id'])) {
            $query->where('exam_id', $data['exam_id']);
        }

        // Rango por start_at/end_at
        if (!empty($data['from'])) {
            $query->where('end_at', '>=', $data['from']);
        }
        if (!empty($data['to'])) {
            $query->where('start_at', '<=', $data['to']);
        }

        return response()->json([
            'data' => $query->paginate(30),
        ]);
    }

    /**
     * Crear evento
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],

            'start_at' => ['required', 'date'],
            'end_at'   => ['required', 'date', 'after_or_equal:start_at'],

            'event_type' => ['required', Rule::in(['exam', 'activity', 'reminder', 'meeting'])],

            'exam_id'  => ['nullable', 'uuid'],
            'group_id' => ['nullable', 'uuid'],
        ]);

        $user = $request->user();

        $event = CalendarEvent::create([
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
            'event_type' => $data['event_type'],
            'exam_id' => $data['exam_id'] ?? null,
            'group_id' => $data['group_id'] ?? null,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'data' => $event->load(['creator', 'group', 'exam']),
        ], 201);
    }

    /**
     * Ver evento
     */
    public function show(CalendarEvent $calendarEvent)
    {
        return response()->json([
            'data' => $calendarEvent->load(['creator', 'group', 'exam']),
        ]);
    }

    /**
     * Actualizar evento
     */
    public function update(Request $request, CalendarEvent $calendarEvent)
    {
        $data = $request->validate([
            'title'       => ['sometimes', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],

            'start_at' => ['sometimes', 'date'],
            'end_at'   => ['sometimes', 'date'],

            'event_type' => ['sometimes', Rule::in(['exam', 'activity', 'reminder', 'meeting'])],

            'exam_id'  => ['nullable', 'uuid'],
            'group_id' => ['nullable', 'uuid'],
        ]);

        // Si vienen ambos, validamos consistencia
        $start = $data['start_at'] ?? $calendarEvent->start_at;
        $end   = $data['end_at'] ?? $calendarEvent->end_at;

        if (strtotime((string)$end) < strtotime((string)$start)) {
            return response()->json([
                'message' => 'end_at debe ser mayor o igual a start_at',
            ], 422);
        }

        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        $calendarEvent->fill($data);
        $calendarEvent->save();

        return response()->json([
            'data' => $calendarEvent->fresh()->load(['creator', 'group', 'exam']),
        ]);
    }

    /**
     * Eliminar evento
     */
    public function destroy(CalendarEvent $calendarEvent)
    {
        $calendarEvent->delete();

        return response()->json([
            'message' => 'Evento eliminado',
        ]);
    }
}