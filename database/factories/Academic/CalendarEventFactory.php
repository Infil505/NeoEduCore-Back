<?php

namespace Database\Factories\Academic;

use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

class CalendarEventFactory extends Factory
{
    protected $model = \App\Models\Academic\CalendarEvent::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+1 month');

        return [
            'institution_id' => Institution::factory(),
            'title'          => fake()->sentence(4),
            'description'    => fake()->optional()->sentence(),
            'start_at'       => $start,
            'end_at'         => fake()->dateTimeBetween($start, '+2 months'),
            'event_type'     => fake()->randomElement(['activity', 'reminder', 'meeting']),
            'exam_id'        => null,
            'group_id'       => null,
            'created_by'     => null,
        ];
    }
}
