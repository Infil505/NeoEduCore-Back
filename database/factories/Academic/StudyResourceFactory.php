<?php

namespace Database\Factories\Academic;

use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudyResourceFactory extends Factory
{
    protected $model = \App\Models\Academic\StudyResource::class;

    public function definition(): array
    {
        // Los grados salen de la configuración del sistema (1.º–6.º): antes eran 7–12,
        // un rango que el sistema ya no admite desde el 13/09/2026.
        $min = fake()->numberBetween((int) config('academic.grade_min'), (int) config('academic.grade_max'));

        return [
            'institution_id'     => Institution::factory(),
            'title'              => fake()->sentence(5),
            'description'        => fake()->optional()->sentence(),
            'resource_type'      => fake()->randomElement(['video', 'article', 'pdf', 'link']),
            'url'                => fake()->url(),
            'estimated_duration' => fake()->optional()->numberBetween(5, 60),
            'difficulty'         => fake()->randomElement(['basic', 'intermediate', 'advanced']),
            'grade_min'          => $min,
            'grade_max'          => fake()->numberBetween($min, (int) config('academic.grade_max')),
            'language'           => 'es',
            'created_by'         => null,
        ];
    }

    /**
     * Enviado a estas aulas. Un recurso sin aula solo lo ven su autor y el
     * administrador: para uno que un estudiante deba ver, usar este estado.
     *
     * @param  iterable<\App\Models\Academic\Group|string>  $aulas  modelos o ids
     */
    public function enAulas(iterable $aulas): static
    {
        return $this->afterCreating(function (\App\Models\Academic\StudyResource $recurso) use ($aulas) {
            $ids = collect($aulas)->map(fn ($a) => is_string($a) ? $a : $a->id)->all();
            $recurso->syncGroups($ids);
        });
    }
}
