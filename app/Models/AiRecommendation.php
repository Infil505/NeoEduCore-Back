<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiRecommendation extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ai_recommendations';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'student_user_id',
        'subject_id',
        'exam_id',
        'recommendation_text',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_user_id', 'user_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}