<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class AiRecommendation extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'ai_recommendations';

    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'institution_id',
        'student_user_id',
        'subject_id',
        'exam_id',
        'type',
        'recommendation_text',
        'resource',
    ];

    protected $casts = [
        'resource'   => 'array',
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