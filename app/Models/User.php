<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Enums\UserType;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory, HasUuids;

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

    protected $hidden = ['password_hash'];

    protected $casts = [
        'user_type' => UserType::class,
        'status' => UserStatus::class,
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function studentProfile()
    {
        return $this->hasOne(Student::class, 'user_id');
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }


}