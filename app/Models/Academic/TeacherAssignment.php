<?php

namespace App\Models\Academic;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Asignación de un docente a un grupo para una materia concreta.
 *
 * Es la única fuente de la relación docente↔estudiante: todo el alcance de un
 * docente se resuelve desde aquí (ver `AcotaAlDocente`). Solo el admin la crea.
 */
class TeacherAssignment extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'teacher_assignments';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'teacher_user_id',
        'group_id',
        'subject_id',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
