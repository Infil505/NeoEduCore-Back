<?php

namespace App\Models\Exams;

use App\Enums\ExamStatus;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Academic\Subject;
use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class Exam extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'exams';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'created_by_teacher_id',

        // RN-EXAM-001
        'title',
        'subject_id',
        'grade',                 // 7–12

        // RN-EXAM-002
        'instructions',

        // Vídeo de apoyo opcional (03/10/2026); ver migración add_video_url_to_exams
        'video_url',
        // Enlaces de apoyo (vídeo/texto) que el tutor usa al recomendar; ver migración 08/10/2026
        'support_resources',

        // RN-EXAM-004..007
        'duration_minutes',

        // RN-EXAM-017
        'status',                // draft | published | active | completed

        // RN-EXAM-034 / RN-EXAM-035
        'max_attempts',
        'show_results_immediately',
        'allow_review_after_submission',
        'randomize_questions',

        // Ventana de disponibilidad
        'available_from',
        'available_until',
    ];

    protected $casts = [
        'grade' => 'integer',
        'duration_minutes' => 'integer',
        'status' => ExamStatus::class,
        'support_resources' => 'array',

        'max_attempts' => 'integer',
        'show_results_immediately' => 'boolean',
        'allow_review_after_submission' => 'boolean',
        'randomize_questions' => 'boolean',

        'available_from' => 'datetime',
        'available_until' => 'datetime',
    ];

    /**
     * Acota la consulta a los exámenes que un usuario tiene derecho a ver.
     *
     * Para **admin y docente** no cambia nada: gestionan el catálogo completo de
     * su institución.
     *
     * Para un **docente** devuelve solo los suyos (autoría) y para el
     * administrador todos los de la institución.
     *
     * Para un **estudiante** exige las tres condiciones que definen que un
     * examen es suyo: publicado y activo, dentro de la ventana de disponibilidad
     * y asignado a alguno de sus grupos. Sin esto, `GET /exams` entregaba el
     * catálogo entero —incluidos borradores— y con los ids en la mano
     * `GET /exams/{id}` servía los enunciados antes de presentar la prueba. Las
     * respuestas correctas nunca se filtraron (van ocultas en los modelos, ver
     * `RevelaRespuestas`), pero conocer las preguntas de antemano ya invalida el
     * diagnóstico.
     *
     * Es la misma regla que aplicaba `StudentController::availableExams()`;
     * vive aquí para que exista **una sola** definición de «examen visible» y no
     * se olvide al añadir un endpoint nuevo.
     *
     * Nota (cambiada el 05/10/2026): el estudiante tiene que estar **matriculado
     * ahora** en el aula (`left_at IS NULL`). Antes la pertenencia no descartaba
     * a quien la dejó, y un alumno que cambiaba de aula seguía viendo —y
     * pudiendo presentar— los exámenes de la anterior. La matrícula se hace al
     * inicio de curso: sin aula, no ve nada.
     */
    public function scopeVisibleTo($query, ?object $user)
    {
        // Un **docente** solo ve los exámenes que él creó: entre docentes no se
        // ve lo que tiene cada uno, ni siquiera dentro del mismo grupo y
        // materia. El administrador ve los de toda la institución. Un examen
        // sin autor (`created_by_teacher_id` NULL tras borrar la cuenta) queda
        // para el administrador.
        if ($user && $user->user_type === \App\Enums\UserType::Teacher) {
            return $query->where('created_by_teacher_id', $user->id);
        }

        if (!$user || $user->user_type !== \App\Enums\UserType::Student) {
            return $query;
        }

        return $query
            ->where('status', ExamStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>=', now()))
            ->asignadoAlAulaDe($user);
    }

    /**
     * El examen está enviado a un aula donde el estudiante está matriculado
     * ahora. Es la condición de «es para mí», sin mirar estado ni ventana; la
     * usan `scopeVisibleTo()` y el inicio de intentos.
     */
    public function scopeAsignadoAlAulaDe($query, object $user)
    {
        return $query->whereHas('groups', fn ($q) => $q->whereIn(
            'groups.id',
            \Illuminate\Support\Facades\DB::table('group_students')
                ->select('group_id')
                // El examen ya viene acotado por TenantScoped, así que la
                // subconsulta no podía traer nada ajeno; dicho así, no hay
                // que deducirlo.
                ->where('institution_id', $user->institution_id)
                ->where('student_user_id', $user->id)
                ->whereNull('left_at')
        ));
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Los alumnos a quienes hay que avisar de este examen: LA regla de «quién
     * lo ve», en un solo sitio. La usan la notificación en la app
     * (`NotificarExamenDisponible`) y el aviso en vivo (`ExamenActivado`).
     *
     * Miembros vigentes (sin `left_at`) de algún grupo destino, de la misma
     * institución y con la cuenta activa: una inactiva todavía no ha entrado
     * nunca y una suspendida no debe recibir nada. Es el mismo criterio de
     * grupo que `scopeAsignadoAlAulaDe()`, visto desde el lado del alumno.
     *
     * **El tenant va explícito.** En el worker no hay `SetTenantFromAuth`, así
     * que `TenantScoped` no filtra y nada más ataría la consulta al centro.
     */
    public function destinatarios(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->where('institution_id', $this->institution_id)
            ->where('user_type', \App\Enums\UserType::Student->value)
            ->where('status', \App\Enums\UserStatus::Active->value)
            ->whereIn('id', \Illuminate\Support\Facades\DB::table('group_students')
                ->select('student_user_id')
                ->where('institution_id', $this->institution_id)
                ->whereNull('left_at')
                ->whereIn('group_id', \Illuminate\Support\Facades\DB::table('exam_targets')
                    ->select('group_id')
                    ->where('institution_id', $this->institution_id)
                    ->where('exam_id', $this->id)));
    }

    /**
     * Pone el examen en el calendario de cada aula destino, al activarse.
     *
     * Un evento por aula, como el resto del calendario (`CalendarEvent` lleva
     * un solo `group_id`): el alumnado lo ve por su matrícula vigente en el
     * aula (`CalendarEvent::scopeVisibleTo()`), que es la misma regla de «quién
     * ve el examen». Las fechas son informativas; quien decide si se puede
     * abrir sigue siendo `available_from`, aplicada por la API.
     *
     * - Empieza en `available_from` (o ahora si no hay) y acaba en
     *   `available_until` (o, sin él, tras `duration_minutes`).
     * - Lo crea a nombre del docente del examen, así también lo ve en su agenda.
     * - Si el aula ya tiene un evento de este examen —p. ej. uno que el docente
     *   puso a mano— no se duplica.
     *
     * @return int cuántos eventos se crearon
     */
    public function publicarEnCalendario(): int
    {
        $inicio = $this->available_from ?? now();
        $fin    = $this->available_until ?? $inicio->copy()->addMinutes((int) $this->duration_minutes);

        $yaTienen = CalendarEvent::query()
            ->where('exam_id', $this->id)
            ->pluck('group_id')
            ->all();

        $creados = 0;

        foreach ($this->groups()->pluck('groups.id') as $groupId) {
            if (in_array($groupId, $yaTienen, true)) {
                continue;
            }

            CalendarEvent::create([
                'institution_id' => $this->institution_id,
                'title'          => 'Examen: ' . $this->title,
                'description'    => $this->subject?->name,
                'start_at'       => $inicio,
                'end_at'         => $fin,
                'event_type'     => \App\Enums\CalendarEventType::Exam,
                'exam_id'        => $this->id,
                'group_id'       => $groupId,
                'created_by'     => $this->created_by_teacher_id,
            ]);
            $creados++;
        }

        return $creados;
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'created_by_teacher_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function groups()
    {
        return $this->belongsToMany(
            Group::class,
            'exam_targets',
            'exam_id',
            'group_id'
        )->withPivot(['institution_id']);
    }

    public function syncGroups(array $groupIds): void
    {
        $this->groups()->syncWithPivotValues($groupIds, [
            'institution_id' => $this->institution_id,
        ]);
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function attempts()
    {
        return $this->hasMany(ExamAttempt::class);
    }
}
