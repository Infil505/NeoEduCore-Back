<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class StudentProgress extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'student_progress';

    public $incrementing = false;
    protected $keyType = 'string';

    // Guardar updated_at pero NO created_at
    const CREATED_AT = null;
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'institution_id',

        'student_user_id',
        'subject_id',

        // Progreso por materia (0-100)
        'mastery_percentage',

        // Timestamp de última actualización
        'updated_at',
    ];

    protected $casts = [
        'mastery_percentage' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_user_id', 'user_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}