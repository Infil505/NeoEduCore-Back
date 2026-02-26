<?php

namespace App\Models;

use App\Enums\StudentStatus;
use App\Enums\AdecuacionType;
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
        // Tipo de adecuación (si aplica)
        'adecuacion_type',
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
        'adecuacion_type'       => AdecuacionType::class,
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function groups()
    {
        return $this->belongsToMany(
            Group::class,
            'group_students',
            'student_user_id',
            'group_id'
        )->withPivot(['joined_at', 'left_at'])
         ->withTimestamps(false);
    }

    public function attempts()
    {
        return $this->hasMany(ExamAttempt::class, 'student_user_id', 'user_id');
    }

    public function progress()
    {
        return $this->hasMany(StudentProgress::class, 'student_user_id', 'user_id');
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