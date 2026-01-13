<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class ExamAttempt extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'exam_attempts';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',

        'exam_id',
        'student_user_id',

        // RN-EXAM-034 (intentos)
        'attempt_number',

        // Timestamps del intento
        'started_at',
        'submitted_at',

        // Resultados
        'score',
        'max_score',

        // RN-GRADE-007 (estado de calificación)
        'grade_status', // pending | graded | completed
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'started_at'     => 'datetime',
        'submitted_at'   => 'datetime',

        'score'          => 'decimal:2',
        'max_score'      => 'decimal:2',
    ];

    /* =========================
     | Accesores (RN-GRADE-002/003)
     ========================= */

    public function getPercentageAttribute(): float
    {
        $max = (float) $this->max_score;
        if ($max <= 0) return 0;

        $pct = ((float) $this->score / $max) * 100;
        return round($pct, 2);
    }

    public function getDisplayScoreAttribute(): string
    {
        $score = rtrim(rtrim(number_format((float)$this->score, 2, '.', ''), '0'), '.');
        $max   = rtrim(rtrim(number_format((float)$this->max_score, 2, '.', ''), '0'), '.');

        return "{$score}/{$max} ({$this->percentage}%)";
    }

    /* =========================
     | Relaciones
     ========================= */

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