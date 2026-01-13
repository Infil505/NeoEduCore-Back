<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class StudentAnswer extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'student_answers';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'attempt_id',
        'question_id',

        // Respuesta del estudiante
        'answer_text',

        // Resultado de la evaluación
        'is_correct',
        'points_awarded',

        // RN-GRADE-023
        'correct_answer_snapshot',
        'explanation',

        // Trazabilidad
        'answered_at',
        'review_status',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'points_awarded' => 'decimal:2',
        'correct_answer_snapshot' => 'array',
        'answered_at' => 'datetime',
    ];

    public function attempt()
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function selectedOptions()
    {
        return $this->belongsToMany(
            QuestionOption::class,
            'student_answer_options',
            'student_answer_id',
            'option_id'
        )->withPivot(['institution_id']);
    }
}