<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class CalendarEvent extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'calendar_events';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',

        // Contexto del evento
        'title',
        'description',

        // Fechas
        'start_at',
        'end_at',

        // Tipo de evento
        'event_type',      // exam | activity | reminder | meeting

        // Asociación opcional
        'exam_id',
        'group_id',

        // Auditoría
        'created_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}