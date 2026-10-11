<?php

namespace App\Models\Exams;

use App\Models\Admin\Institution;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * La lectura del análisis de un examen redactada por IA, guardada: una por examen (la última sustituye a la anterior).
 * `fingerprint` es la huella de los datos con los que se redactó; si ya no coincide con la de ahora, la lectura sigue
 * mostrándose pero marcada como desactualizada. Ver `ExamAnalysisNarrative` (servicio) y la migración `2026_10_11_000003`.
 */
class ExamNarrative extends Model
{
    use HasUuids, TenantScoped;

    protected $table = 'exam_analysis_narratives';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'exam_id',
        'fingerprint',
        'narrative',
        'generated_at',
    ];

    protected $casts = [
        'narrative'    => 'array',
        'generated_at' => 'datetime',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}
