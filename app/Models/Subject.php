<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\TenantScoped;

class Subject extends Model
{
    use HasFactory, HasUuids, TenantScoped;
    protected $table = 'subjects';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'name',
    ];

    protected $casts = [
        'name' => 'string',
    ];

    /* =========================
     | Relaciones
     ========================= */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function exams()
    {
        return $this->hasMany(Exam::class);
    }
}