<?php

namespace App\Models;

use App\Enums\StudentStatus;
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
}