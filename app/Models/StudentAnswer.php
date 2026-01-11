<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StudentAnswer extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'student_answers';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'attempt_id',
        'question_id',
        'answer_text',
        'is_correct',
        'points_awarded',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'points_awarded' => 'decimal:2',
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