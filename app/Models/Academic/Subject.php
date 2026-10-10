<?php

namespace App\Models\Academic;

use App\Models\Admin\Institution;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\Exams\Exam;
use App\Models\Students\StudentProgress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class Subject extends Model
{
    use HasFactory, HasUuids, TenantScoped;
    protected $table = 'subjects';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Las fechas de creación y edición no salen en el JSON: ninguna pantalla las usa
     * y este catálogo se repite en cada respuesta (resumen, directorio, avisos…).
     * Siguen siendo atributos del modelo.
     */
    protected $hidden = ['created_at', 'updated_at'];

    /**
     * Añade a cada materia sus cifras (`exams_count`, `student_count`, `avg_mastery`) como
     * subselects: UNA consulta en vez de una por tabla (materias, exámenes, progreso). Con la
     * base remota cada viaje cuesta ~0,4 s. Hay que pedir las columnas de la materia aparte.
     */
    public function scopeConCifras($query)
    {
        return $query
            ->selectRaw('(SELECT COUNT(*) FROM exams e
                          WHERE e.subject_id = subjects.id AND e.institution_id = subjects.institution_id) AS exams_count')
            ->selectRaw('(SELECT COUNT(*) FROM student_progress sp
                          WHERE sp.subject_id = subjects.id AND sp.institution_id = subjects.institution_id) AS student_count')
            ->selectRaw('(SELECT AVG(sp.mastery_percentage) FROM student_progress sp
                          WHERE sp.subject_id = subjects.id AND sp.institution_id = subjects.institution_id) AS avg_mastery');
    }

    protected $fillable = [
        'institution_id',
        'name',
    ];

    protected $casts = [
        'name' => 'string',
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function exams()
    {
        return $this->hasMany(Exam::class);
    }

    public function studentSubjects()
    {
        return $this->hasMany(StudentSubject::class);
    }

    public function teacherAssignments()
    {
        return $this->hasMany(TeacherAssignment::class);
    }

    public function studyResources()
    {
        return $this->hasMany(StudyResource::class);
    }

    public function studentProgress()
    {
        return $this->hasMany(StudentProgress::class);
    }

    public function aiRecommendations()
    {
        return $this->hasMany(AiRecommendation::class);
    }

    public function aiChatSessions()
    {
        return $this->hasMany(AiChatSession::class);
    }
}
