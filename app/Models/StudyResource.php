<?php

namespace App\Models;

use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class StudyResource extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'study_resources';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',

        // RN-AI-008
        'title',
        'description',
        'resource_type',        // video | article | exercise | book | pdf | link
        'url',

        // Metadatos recomendados RN-AI-008
        'estimated_duration',   // minutos
        'difficulty',           // basic | intermediate | advanced
        'grade_min',            // grado mínimo recomendado
        'grade_max',            // grado máximo recomendado
        'language',             // default: 'es'

        // Auditoría
        'created_by',
    ];

    protected $casts = [
        'resource_type'       => ResourceType::class,
        'estimated_duration'  => 'integer',
        'grade_min'           => 'integer',
        'grade_max'           => 'integer',
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}