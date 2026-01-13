<?php

namespace App\Models;

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
        'code',      // Código institucional (RN-GLOB-001)
        'name',      // Nombre completo
        'address',
        'phone',
        'email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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