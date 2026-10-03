<?php

namespace App\Models\AI;

use App\Enums\AiGenerationSource;
use App\Enums\AiRecommendationType;
use App\Models\Students\Student;
use App\Models\Academic\Subject;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class AiRecommendation extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'ai_recommendations';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'student_user_id',
        'subject_id',
        'exam_id',
        'attempt_id',
        'recommendation_text',
        'generated_at',
        'recommendation_type',
        'resource',
        'generated_by',
    ];

    protected $casts = [
        'generated_at'        => 'datetime',
        'resource'            => 'array',
        'recommendation_type' => AiRecommendationType::class,
        'generated_by'        => AiGenerationSource::class,
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_user_id', 'user_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function attempt()
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }
}
