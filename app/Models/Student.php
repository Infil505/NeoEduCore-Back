<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Student extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'students';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'student_code',
        'birth_date',
        'parent_name',
        'parent_email',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_students', 'student_user_id', 'group_id')
            ->withPivot(['joined_at', 'left_at'])
            ->withTimestamps(false);
    }

    public function attempts()
    {
        return $this->hasMany(ExamAttempt::class, 'student_user_id', 'user_id');
    }
}