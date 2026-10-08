<?php

namespace App\Models\Academic;

use App\Enums\CalendarEventType;
use App\Enums\UserType;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
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

        // Avisos del centro (admin): 'students' | 'teachers' | 'all'. NULL = evento de sección.
        'audience',

        // Asociación opcional
        'exam_id',
        'group_id',

        // Auditoría
        'created_by',
    ];

    protected $casts = [
        'start_at'   => 'datetime',
        'end_at'     => 'datetime',
        'event_type' => CalendarEventType::class,
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

    /** Destinatarios de un aviso del centro. */
    public const AUDIENCES = ['students', 'teachers', 'all'];

    /**
     * Quién ve qué. Único sitio donde se define.
     *
     *  - **Administrador**: todos los de la institución.
     *  - **Docente**: los que él creó, más los avisos del centro para docentes
     *    (`audience` teachers/all).
     *  - **Estudiante**: los de un aula donde está matriculado ahora, más los
     *    avisos del centro para estudiantes (`audience` students/all). Un evento
     *    sin aula ni destinatarios (anteriores a la regla) no llega a nadie.
     */
    public function scopeVisibleTo($query, ?object $user)
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->user_type === UserType::Teacher) {
            return $query->where(fn ($q) => $q
                ->where('created_by', $user->id)
                ->orWhereIn('audience', ['teachers', 'all']));
        }

        if ($user->user_type === UserType::Student) {
            return $query->where(fn ($q) => $q
                ->whereIn(
                    'group_id',
                    DB::table('group_students')
                        ->select('group_id')
                        ->where('institution_id', $user->institution_id)
                        ->where('student_user_id', $user->id)
                        ->whereNull('left_at')
                )
                ->orWhereIn('audience', ['students', 'all']));
        }

        return $query;
    }
}
