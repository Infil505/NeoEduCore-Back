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

    protected $fillable = ['code', 'name'];
}