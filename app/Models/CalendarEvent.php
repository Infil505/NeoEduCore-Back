<?php

namespace App\Models;

use App\Enums\CalendarTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CalendarEvent extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'calendar_events';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'institution_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'target_type',
        'target_id',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'target_type' => CalendarTargetType::class,
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
