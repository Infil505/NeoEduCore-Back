<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class Question extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'questions';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'exam_id',

        // RN-EXAM-011
        'question_text',
        'question_type',     // multiple_choice | true_false | short_answer

        // RN-EXAM-013 (1–10)
        'points',

        // Para preguntas de respuesta corta
        'correct_answer_text',

        // Orden dentro del examen
        'order_index',
    ];

    protected $casts = [
        'question_type' => QuestionType::class,
        'points'        => 'integer',
        'order_index'   => 'integer',
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class, 'question_id');
    }

    public function studentAnswers()
    {
        return $this->hasMany(StudentAnswer::class, 'question_id');
    }
}