<?php

namespace App\Models\Exams;

use App\Enums\QuestionType;
use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class Question extends Model
{
    /**
     * La respuesta de las preguntas abiertas tampoco se serializa por defecto.
     * Ver el comentario equivalente en QuestionOption.
     */
    protected $hidden = ['correct_answer_text'];

    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'questions';

    /** Todas las columnas de `questions`, para unir la pregunta a otra consulta (`RelacionesEnLinea`). */
    public const COLUMNAS = [
        'id', 'institution_id', 'exam_id', 'question_text', 'question_type', 'points', 'correct_answer_text',
        'order_index', 'created_at', 'updated_at', 'topic', 'indicator', 'difficulty', 'topic_normalized',
    ];

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

        // Metadatos curriculares (D2): tema e indicador tal como los escribe el
        // docente, y nivel del ítem. `topic_normalized` NO va aquí: la genera
        // PostgreSQL a partir de `topic` y escribirla daría error.
        'topic',
        'indicator',
        'difficulty',
    ];

    protected $casts = [
        'question_type' => QuestionType::class,
        'points'        => 'integer',
        'order_index'   => 'integer',
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

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
        return $this->hasMany(\App\Models\Students\StudentAnswer::class, 'question_id');
    }
}
