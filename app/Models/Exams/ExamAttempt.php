<?php

namespace App\Models\Exams;

use App\Enums\AiRecommendationsStatus;
use App\Enums\GradeStatus;
use App\Models\Students\Student;
use App\Models\Admin\Institution;
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

        'attempt_number',

        'started_at',
        'submitted_at',
        'paused_at',
        'total_paused_seconds',

        'score',
        'max_score',

        'grade_status',

        'ai_recommendations_status',

        'draft_answers',
        'draft_saved_at',
    ];

    // El borrador solo se sirve por su endpoint propio: no debe viajar en cada JSON del intento.
    protected $hidden = ['draft_answers'];

    protected $casts = [
        'draft_answers'  => 'array',
        'draft_saved_at' => 'datetime',
        'attempt_number'       => 'integer',
        'started_at'           => 'datetime',
        'submitted_at'         => 'datetime',
        'paused_at'            => 'datetime',
        'total_paused_seconds' => 'integer',

        'score'      => 'decimal:2',
        'max_score'  => 'decimal:2',

        'grade_status' => GradeStatus::class,

        'ai_recommendations_status' => AiRecommendationsStatus::class,
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

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

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
        return $this->hasMany(\App\Models\Students\StudentAnswer::class, 'attempt_id');
    }
}
