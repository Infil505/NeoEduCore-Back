<?php

namespace App\Models\AI;

use App\Models\Students\Student;
use App\Models\Academic\Subject;
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

        // Contenido generado por IA
        'recommendation_text',
        'generated_at',

        // Tipo de recomendación
        'recommendation_type', // study_plan | support_resource | etc
    ];

    protected $casts = [
        'generated_at' => 'datetime',
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
}
