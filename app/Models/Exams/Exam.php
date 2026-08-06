<?php

namespace App\Models\Exams;

use App\Enums\ExamStatus;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Academic\Subject;
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
     * Nota: la pertenencia al grupo no descarta a quien lo dejó (`left_at`),
     * porque replica el comportamiento que ya tenía `availableExams()`. Cambiarlo
     * afectaría a quién puede presentar exámenes, no solo a quién los ve.
     */
    public function scopeVisibleTo($query, ?object $user)
    {
        if (!$user || $user->user_type !== \App\Enums\UserType::Student) {
            return $query;
        }

        return $query
            ->where('status', ExamStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>=', now()))
            ->whereHas('groups', fn ($q) => $q->whereIn(
                'groups.id',
                \Illuminate\Support\Facades\DB::table('group_students')
                    ->select('group_id')
                    ->where('student_user_id', $user->id)
            ));
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
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
