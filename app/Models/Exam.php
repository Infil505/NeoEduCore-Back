<?php

namespace App\Models;

use App\Enums\ExamStatus;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Exam extends Model
{
    use HasFactory, HasUuids;
    // use TenantScoped; // opcional

    protected $table = 'exams';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'created_by_teacher_id',
        'subject_id',
        'title',
        'description',
        'duration_minutes',
        'passing_percentage',
        'status',
        'scheduled_at',
        'available_from',
        'available_until',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'passing_percentage' => 'decimal:2',
        'status' => ExamStatus::class,
        'scheduled_at' => 'datetime',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'created_by_teacher_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'exam_targets', 'exam_id', 'group_id')
            ->withPivot(['institution_id'])
            ->withTimestamps(false);
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function attempts()
    {
        return $this->hasMany(ExamAttempt::class);
    }
}