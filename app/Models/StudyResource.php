<?php

namespace App\Models;

use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StudyResource extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'study_resources';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'title',
        'description',
        'resource_type',
        'url',
        'created_by',
    ];

    protected $casts = [
        'resource_type' => ResourceType::class,
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}