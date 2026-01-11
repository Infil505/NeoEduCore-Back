<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StudentProgress extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'student_progress';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'student_user_id',
        'subject_id',
        'mastery_percentage',
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