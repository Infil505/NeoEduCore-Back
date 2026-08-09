<?php

namespace App\Models\Admin;

use App\Models\Academic\Subject;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Institution extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'institutions';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'email',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings'  => 'array',
    ];

    public static array $defaultSettings = [
        'timezone'           => 'America/Costa_Rica',
        'language'           => 'es',
        'logo_url'           => null,
        'max_exam_duration'  => 180,
        'allow_registration' => true,
        'contact_email'      => null,

        // Nota mínima de aprobación (%) usada por los reportes para separar
        // aprobados de no aprobados y para los niveles de desempeño. 65 es el
        // mínimo de promoción del MEP en I y II ciclo; cada centro puede
        // cambiarlo desde PUT /api/system/config.
        'passing_percentage' => 65,
    ];

    /* =========================
     | Mutators
     ========================= */

    /**
     * RN-USER-008: el código institucional debe guardarse en mayúsculas
     */
    public function setCodeAttribute($value)
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    /* =========================
     | Relaciones
     ========================= */

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function groups()
    {
        return $this->hasMany(Group::class);
    }

    public function studyResources()
    {
        return $this->hasMany(StudyResource::class);
    }
}
