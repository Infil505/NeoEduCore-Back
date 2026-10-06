<?php

namespace App\Models\Admin;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Subject;
use App\Models\Academic\Group;
use App\Models\Academic\StudentSubject;
use App\Models\Academic\StudyResource;
use App\Models\Academic\TeacherAssignment;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\AI\AiTutorIncident;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\Student;
use App\Models\Students\StudentAnswer;
use App\Models\Students\StudentProgress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;

class Institution extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'institutions';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'email',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings'  => 'array',
    ];

    /**
     * ¿La institución está dada de alta? Lo consulta `EnsureAccountIsActive` en
     * cada petición autenticada, así que se cachea; `booted()` lo invalida en
     * cuanto la institución se guarda o se borra, para que la baja surta efecto
     * al instante. Una institución que no existe cuenta como no activa.
     */
    public static function estaActiva(string $id): bool
    {
        return Cache::remember(
            self::claveActiva($id),
            60,
            fn () => (bool) static::query()->whereKey($id)->value('is_active')
        );
    }

    private static function claveActiva(string $id): string
    {
        return "institution.activa.{$id}";
    }

    protected static function booted(): void
    {
        $olvidar = fn (self $institucion) => Cache::forget(self::claveActiva($institucion->id));

        static::saved($olvidar);
        static::deleted($olvidar);
    }

    public static array $defaultSettings = [
        'timezone'           => 'America/Costa_Rica',
        'language'           => 'es',
        'logo_url'           => null,
        'max_exam_duration'  => 180,
        'allow_registration' => true,
        'contact_email'      => null,

        // Nota mínima de aprobación (%) usada por los reportes para separar
        // aprobados de no aprobados y para los niveles de desempeño. 65 es el
        // mínimo de promoción del MEP en I y II ciclo; cada centro puede
        // cambiarlo desde PUT /api/system/config.
        'passing_percentage' => 65,
    ];

    /* =========================
     | Mutators
     ========================= */

    /**
     * RN-USER-008: el código institucional debe guardarse en mayúsculas
     */
    public function setCodeAttribute($value)
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    /* =========================
     | Relaciones
     ========================= */

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function groups()
    {
        return $this->hasMany(Group::class);
    }

    public function studyResources()
    {
        return $this->hasMany(StudyResource::class);
    }

    /*
     | Las relaciones de abajo apuntan a modelos con `TenantScoped`: fuera del
     | tenant propio (p. ej. el superadmin, sin `tenant_id`) la consulta lanza
     | RuntimeException en contexto HTTP. Igual que `subjects()` y `groups()`.
     */

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    public function studentSubjects()
    {
        return $this->hasMany(StudentSubject::class);
    }

    public function teacherAssignments()
    {
        return $this->hasMany(TeacherAssignment::class);
    }

    public function calendarEvents()
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function exams()
    {
        return $this->hasMany(Exam::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function questionOptions()
    {
        return $this->hasMany(QuestionOption::class);
    }

    public function examAttempts()
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function studentAnswers()
    {
        return $this->hasMany(StudentAnswer::class);
    }

    public function studentProgress()
    {
        return $this->hasMany(StudentProgress::class);
    }

    public function aiRecommendations()
    {
        return $this->hasMany(AiRecommendation::class);
    }

    public function aiChatSessions()
    {
        return $this->hasMany(AiChatSession::class);
    }

    /** Sin `TenantScoped`: esta sí la puede leer el superadmin. */
    public function aiTutorIncidents()
    {
        return $this->hasMany(AiTutorIncident::class);
    }
}
