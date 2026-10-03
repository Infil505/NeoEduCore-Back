<?php

namespace App\Models\AI;

use App\Models\Concerns\TenantScoped;
use App\Models\Academic\Subject;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiChatSession extends Model
{
    use HasFactory, HasUuids, TenantScoped;

    protected $table = 'ai_chat_sessions';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'student_user_id',
        'subject_id',
        'exam_id',
        'messages',
        'ended_at',
    ];

    protected $casts = [
        'messages' => 'array',
        'ended_at' => 'datetime',
        // O7: hay una respuesta del modelo en la cola. Se escribe con forceFill.
        'awaiting_reply_since' => 'datetime',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

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
