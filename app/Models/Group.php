<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Group extends Model
{
    use HasFactory, HasUuids;
    // use TenantScoped; // opcional

    protected $table = 'groups';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'grade',
        'section',
        'year',
        'group_code',
    ];

    protected $casts = [
        'grade' => 'integer',
        'year' => 'integer',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'group_students', 'group_id', 'student_user_id')
            ->withPivot(['joined_at', 'left_at'])
            ->withTimestamps(false);
    }

    public function exams()
    {
        return $this->belongsToMany(Exam::class, 'exam_targets', 'group_id', 'exam_id')
            ->withPivot(['institution_id'])
            ->withTimestamps(false);
    }
}