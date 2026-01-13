<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class Group extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'groups';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',

        // RN-STU-009/010
        'name',           // nombre descriptivo (ej: "7-A 2026")
        'grade',          // 6-12
        'section',        // A-D

        // Apoyo (según tu diseño)
        'year',
        'group_code',

        // RN-STU-009
        'student_count',  // contador de estudiantes
    ];

    protected $casts = [
        'grade'         => 'integer',
        'year'          => 'integer',
        'student_count' => 'integer',
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function students()
    {
        return $this->belongsToMany(
            Student::class,
            'group_students',
            'group_id',
            'student_user_id'
        )->withPivot(['joined_at', 'left_at'])
        ->withTimestamps(false);
    }

    public function exams()
    {
        return $this->belongsToMany(
            Exam::class,
            'exam_targets',
            'group_id',
            'exam_id'
        )->withPivot(['institution_id'])
        ->withTimestamps(false);
    }
}