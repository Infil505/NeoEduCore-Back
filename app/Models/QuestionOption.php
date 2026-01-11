<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QuestionOption extends Model
{
    use HasFactory;

    protected $table = 'question_options';
    protected $primaryKey = 'id';
    public $incrementing = true; // bigserial
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'institution_id',
        'question_id',
        'option_index',
        'option_text',
        'is_correct',
    ];

    protected $casts = [
        'option_index' => 'integer',
        'is_correct' => 'boolean',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}