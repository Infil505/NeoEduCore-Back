<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class QuestionOption extends Model
{
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