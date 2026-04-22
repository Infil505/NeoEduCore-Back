<?php

namespace App\Models\AI;

use App\Enums\AiRecommendationType;
use App\Models\Students\Student;
use App\Models\Academic\Subject;
use App\Models\Exams\Exam;
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
        'recommendation_text',
        'generated_at',
        'recommendation_type',
        'resource',
    ];

    protected $casts = [
        'generated_at'        => 'datetime',
        'resource'            => 'array',
        'recommendation_type' => AiRecommendationType::class,
    ];

    /* =========================
     | Relaciones
     ========================= */

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
}
