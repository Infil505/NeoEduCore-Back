<?php

namespace App\Models\Students;

use App\Enums\StudentStatus;
use App\Enums\AdecuacionType;
use App\Enums\LearningStyle;
use App\Models\Admin\User;
use App\Models\Academic\Group;
use App\Models\Exams\ExamAttempt;
use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class Student extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'students';

    // PK compartida con users
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',

        'user_id',

        // RN-USER-012 (studentId único)
        'student_code',

        // RN-USER-011 / RN-USER-014
        'grade',
        'section',

        // RN-STU-001/002/003
        'status',
        'enrolled_at',
        'last_activity_at',
        'exams_completed_count',
        'overall_average',

        // Datos adicionales
        'birth_date',
        'parent_name',
        'parent_email',

        // RN-USER-013 (si decides guardarlo aquí)
        'group_code',
        'adecuacion_type',
        'learning_style',
        'year',
    ];

    protected $casts = [
        'birth_date'            => 'date',
        'grade'                 => 'integer',
        'section'               => 'string',
        'status'                => StudentStatus::class,
        'enrolled_at'           => 'datetime',
        'last_activity_at'      => 'datetime',
        'exams_completed_count' => 'integer',
        'overall_average'       => 'decimal:2',
        'adecuacion_type'  => AdecuacionType::class,
        'learning_style'   => LearningStyle::class,
        'year'             => 'integer',
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Todas las columnas de `students`, para unir el alumno a otra consulta (`RelacionesEnLinea`). */
    public const COLUMNAS = [
        'user_id', 'institution_id', 'student_code', 'grade', 'section', 'year', 'status', 'enrolled_at',
        'last_activity_at', 'exams_completed_count', 'overall_average', 'birth_date', 'parent_name',
        'parent_email', 'group_code', 'adecuacion_type', 'created_at', 'updated_at', 'learning_style',
    ];

    /** Columnas de `users` que se unen al alumno (las ocultas del modelo no salen en el JSON). */
    public const COLUMNAS_USUARIO = [
        'id', 'institution_id', 'email', 'full_name', 'user_type', 'status',
        'created_at', 'updated_at', 'must_change_password',
    ];

    /**
     * El alumno con su usuario, en UNA consulta (LEFT JOIN, ver `RelacionesEnLinea`) en vez
     * de dos (`with('user')`): con la base remota cada viaje cuesta ~0,4 s. Misma forma
     * que `Student::with('user')->find()`; `null` si no existe en el centro del usuario.
     */
    public static function conUsuario(string $userId, ?\Illuminate\Database\Query\Builder $alcance = null): ?self
    {
        $consulta = \App\Support\RelacionesEnLinea::unir(static::query(), [
            'user' => ['users', 'user_id', self::COLUMNAS_USUARIO],
        ])->where('students.user_id', $userId);

        // Con `$alcance` (los alumnos que un docente alcanza, ya filtrados a este alumno) la
        // pertenencia se comprueba en la MISMA consulta, sin otro viaje a la base. El resultado
        // queda en `$alcanzadoPorDocente`, que no es una columna y por eso no sale en el JSON.
        if ($alcance !== null) {
            $consulta->selectRaw('EXISTS (' . $alcance->toSql() . ') AS alcanzado_por_docente', $alcance->getBindings());
        }

        $alumno = $consulta->first();

        if ($alumno !== null) {
            if ($alcance !== null) {
                $alumno->alcanzadoPorDocente = (bool) $alumno->getAttribute('alcanzado_por_docente');
                $alumno->setRawAttributes(\Illuminate\Support\Arr::except($alumno->getAttributes(), 'alcanzado_por_docente'), true);
            }
            \App\Support\RelacionesEnLinea::hidratar([$alumno], ['user' => User::class]);
        }

        return $alumno;
    }

    /** Solo lo rellena `conUsuario()` con `$alcance`: ¿el docente que mira alcanza a este alumno? */
    public ?bool $alcanzadoPorDocente = null;

    public function groups()
    {
        return $this->belongsToMany(
            Group::class,
            'group_students',
            'student_user_id',
            'group_id'
        )->withPivot(['joined_at', 'left_at']);
    }

    public function attempts()
    {
        return $this->hasMany(ExamAttempt::class, 'student_user_id', 'user_id');
    }

    public function progress()
    {
        return $this->hasMany(StudentProgress::class, 'student_user_id', 'user_id');
    }

    public function subjects()
    {
        return $this->belongsToMany(
            \App\Models\Academic\Subject::class,
            'student_subjects',
            'student_user_id',
            'subject_id',
            'user_id',
            'id'
        )->withPivot('enrolled_at')->withTimestamps();
    }

    /**
     * Determina si el estudiante tiene alguna adecuación registrada.
     */
    public function hasAdecuacion(): bool
    {
        return ! is_null($this->adecuacion_type);
    }

    /**
     * Comprueba si el estudiante es de un tipo de adecuación específico.
     * Acepta tanto la instancia de `AdecuacionType` como su valor string.
     */
    public function isAdecuacion(AdecuacionType|string $type): bool
    {
        $value = $type instanceof AdecuacionType ? $type->value : $type;
        return $this->adecuacion_type?->value === $value;
    }

    public function isAdecuacionAcceso(): bool
    {
        return $this->isAdecuacion(AdecuacionType::Acceso);
    }

    public function isAdecuacionContenido(): bool
    {
        return $this->isAdecuacion(AdecuacionType::Contenido);
    }

    public function isAdecuacionEvaluacion(): bool
    {
        return $this->isAdecuacion(AdecuacionType::Evaluacion);
    }
}
