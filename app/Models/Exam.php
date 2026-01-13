<?php

namespace App\Models;

use App\Enums\ExamStatus;
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
        )->withPivot(['institution_id'])
         ->withTimestamps(false);
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