<?php

namespace App\Models\AI;

use App\Enums\AiIncidentStage;
use App\Enums\AiIncidentType;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Incidencia del tutor IA (decision D5). Ver el docblock de la migracion
 * `2026_09_13_000003` para por que aqui no se guarda el texto que la provoco.
 *
 * **No usa `TenantScoped`, y es deliberado.** Lo mismo que `User` e
 * `Institution`: quien lee esta tabla es el superadministrador, que por
 * definicion es externo a cualquier institucion y no tiene `tenant_id` que
 * ligar — con el scope global puesto, toda consulta suya reventaria. El
 * aislamiento se mantiene igual porque `institution_id` se escribe siempre de
 * forma explicita, desde el alumno que origino la incidencia, y el unico
 * endpoint que la expone solo devuelve agregados.
 */
class AiTutorIncident extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ai_tutor_incidents';

    public $incrementing = false;
    protected $keyType = 'string';

    /** Una incidencia es un hecho pasado: se inserta y no se toca. */
    public $timestamps = false;

    protected $fillable = [
        'institution_id',
        'student_user_id',
        'session_id',
        'type',
        'stage',
        'occurred_at',
    ];

    protected $casts = [
        'type'        => AiIncidentType::class,
        'stage'       => AiIncidentStage::class,
        'occurred_at' => 'datetime',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /** Apunta a `users`, no a `students`: la incidencia sobrevive al perfil (`SET NULL`). */
    public function student()
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function session()
    {
        return $this->belongsTo(AiChatSession::class, 'session_id');
    }
}
