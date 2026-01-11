<?php

namespace App\Models;

use App\Enums\QuestionType;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Question extends Model
{
    use HasFactory, HasUuids;
    // use TenantScoped; // opcional

    protected $table = 'questions';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'exam_id',
        'question_number',
        'question_type',
        'question_text',
        'points',
        'correct_boolean',
        'correct_text',
        'allow_multiple_correct',
        'min_correct',
        'max_correct',
    ];

    protected $casts = [
        'question_number' => 'integer',
        'question_type' => QuestionType::class,
        'points' => 'decimal:2',
        'correct_boolean' => 'boolean',
        'allow_multiple_correct' => 'boolean',
        'min_correct' => 'integer',
        'max_correct' => 'integer',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class, 'question_id');
    }
}