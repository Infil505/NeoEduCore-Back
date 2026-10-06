<?php

namespace App\Models\Academic;

use App\Enums\ResourceType;
use App\Enums\UserType;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use App\Models\Concerns\TenantScoped;

class StudyResource extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'study_resources';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',

        // Materia del recurso (D2). Nullable: un recurso sin materia sigue
        // valiendo como material genérico, que es lo que son los anteriores
        // a esta columna.
        'subject_id',

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

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Aulas a las que el docente envió el recurso (`study_resource_groups`).
     */
    public function groups()
    {
        return $this->belongsToMany(
            Group::class,
            'study_resource_groups',
            'study_resource_id',
            'group_id'
        )->withPivot(['institution_id']);
    }

    public function syncGroups(array $groupIds): void
    {
        $this->groups()->syncWithPivotValues($groupIds, [
            'institution_id' => $this->institution_id,
        ]);
    }

    /**
     * Quién ve qué (regla del centro, 05/10/2026). Es el único sitio donde se
     * define: listado y detalle pasan por aquí.
     *
     *  - **Administrador**: todos los de la institución.
     *  - **Docente**: solo los que él creó; entre docentes no se ve lo que
     *    tiene cada uno.
     *  - **Estudiante**: los enviados a un aula donde está matriculado ahora.
     *    Un recurso sin aula no lo ve nadie salvo su autor y el administrador.
     */
    public function scopeVisibleTo($query, ?object $user)
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->user_type === UserType::Teacher) {
            return $query->where('created_by', $user->id);
        }

        if ($user->user_type === UserType::Student) {
            return $query->whereHas('groups', fn ($q) => $q->whereIn(
                'groups.id',
                DB::table('group_students')
                    ->select('group_id')
                    ->where('institution_id', $user->institution_id)
                    ->where('student_user_id', $user->id)
                    ->whereNull('left_at')
            ));
        }

        return $query;
    }
}
