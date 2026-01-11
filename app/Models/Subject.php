<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subject extends Model
{
    use HasFactory, HasUuids;
    // use TenantScoped; // opcional

    protected $table = 'subjects';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['institution_id', 'name'];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function exams()
    {
        return $this->hasMany(Exam::class);
    }
}