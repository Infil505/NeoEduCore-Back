<?php

namespace App\Models\AI;

use App\Enums\AiGenerationSource;
use App\Enums\AiRecommendationType;
use App\Models\Students\Student;
use App\Models\Academic\Subject;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Admin\Institution;
use App\Support\TextoDeRecomendacion;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class AiRecommendation extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'ai_recommendations';

    /** Lo que recibe el estudiante (por defecto) y el consejo que se escribe PARA EL DOCENTE sobre un estudiante. */
    public const PARA_ESTUDIANTE = 'student';
    public const PARA_DOCENTE = 'teacher';

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
        'audience',
    ];

    protected $casts = [
        'generated_at'        => 'datetime',
        'resource'            => 'array',
        'recommendation_type' => AiRecommendationType::class,
        'generated_by'        => AiGenerationSource::class,
    ];

    /**
     * El texto SIN los bloques JSON que el modelo añadía para el sistema. Ya no se guardan así, pero las
     * recomendaciones anteriores sí los traen: se limpian al leer, para que el docente y el alumno no vean
     * llaves ni enlaces bloqueados. El dato guardado no se toca.
     */
    protected function recommendationText(): Attribute
    {
        return Attribute::get(function (?string $valor) {
            if ($valor === null) {
                return null;
            }

            $limpio = TextoDeRecomendacion::sinBloquesJson($valor);

            return $limpio !== '' ? $limpio : 'Sin detalle disponible.';
        });
    }

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
