<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class QuestionOption extends Model
{
    /**
     * `is_correct` NO se serializa por defecto: un estudiante que lo viera
     * conocería la respuesta antes de entregar. Se revela explícitamente a
     * admin y docente con `RevelaRespuestas::revelarRespuestas()`.
     *
     * Ocultarlo aquí y no en cada controlador hace que la protección sea por
     * defecto: un endpoint nuevo que cargue opciones nace seguro.
     * Ojo: `$hidden` solo afecta a la serialización, no al acceso al atributo,
     * así que la corrección de exámenes sigue funcionando igual.
     */
    protected $hidden = ['is_correct'];

    use HasFactory, TenantScoped;

    protected $table = 'question_options';

    protected $primaryKey = 'id';
    public $incrementing = true;   // bigserial
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'institution_id',
        'question_id',

        // Índice de la opción (1..4 o 1..2)
        'option_index',

        // Texto visible de la opción
        'option_text',

        // Marca si es la correcta
        'is_correct',
    ];

    protected $casts = [
        'option_index' => 'integer',
        'is_correct'   => 'boolean',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
