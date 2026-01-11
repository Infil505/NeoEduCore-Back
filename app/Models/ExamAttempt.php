<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExamAttempt extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'exam_attempts';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'exam_id',
        'student_user_id',
        'attempt_number',
        'started_at',
        'submitted_at',
        'score',
        'max_score',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'percentage' => 'decimal:2',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_user_id', 'user_id');
    }

    public function answers()
    {
        return $this->hasMany(StudentAnswer::class, 'attempt_id');
    }
}