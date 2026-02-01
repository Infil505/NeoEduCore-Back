<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Enums\UserType;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids;

    protected $table = 'users';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'email',
        'password_hash',
        'full_name',
        'user_type',
        'status',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token', // por si Laravel lo busca en algún punto
    ];

    protected $casts = [
        'user_type' => UserType::class,
        'status'    => UserStatus::class,
    ];

    /* =========================
     |  Relaciones
     ========================= */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Perfil de estudiante (solo si user_type = student)
     */
    public function studentProfile()
    {
        return $this->hasOne(Student::class, 'user_id');
    }

    /**
     * Recursos creados por el usuario (teacher/admin)
     */
    public function studyResources()
    {
        return $this->hasMany(StudyResource::class, 'created_by');
    }

    /* =========================
     |  Autenticación
     ========================= */

    /**
     * Laravel usará password_hash como contraseña
     */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }
}