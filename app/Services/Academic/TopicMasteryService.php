<?php

namespace App\Services\Academic;

use App\Models\Students\StudentAnswer;
use Illuminate\Support\Collection;

/**
 * Dominio por **tema**, no por materia (decisión D2).
 *
 * Hasta que `questions` tuvo `topic`, la señal más fina del sistema era
 * `student_progress.mastery_percentage`, que es por materia: «Español 45 %». El
 * informe promete otra cosa —«si un estudiante tiene dificultad en comprensión
 * de lectura…» ([263], [276])— y el docente espera saber qué temas conviene
 * repasar con el grupo ([173]). Las dos cosas salen de la misma agregación, así
 * que vive aquí y no duplicada en el tutor y en los reportes.
 *
 * **Se agrupa por `topic_normalized`, no por `topic`.** La columna la genera
 * PostgreSQL en minúsculas y sin espacios sobrantes, de modo que «Fracciones» y
 * «fracciones » caen en el mismo grupo. Para mostrar se devuelve `MIN(topic)`,
 * una de las formas que escribieron los docentes: es arbitraria pero estable, y
 * cualquier otra elección (la más frecuente, la más reciente) costaría una
 * consulta más para un matiz que nadie va a notar.
 *
 * **Lo que esta agregación no resuelve:** sinónimos y tildes. «Fracciones» y
 * «Fracciones equivalentes» siguen siendo dos temas. Se asumió al decidir D2:
 * arreglarlo pide un catálogo de temas por materia, con su CRUD y su pantalla.
 *
 * Las consultas parten de `StudentAnswer`, que lleva `TenantScoped`, así que el
 * aislamiento por institución lo pone el scope global y no hay que repetirlo.
 */
class TopicMasteryService
{
    /** Por debajo de esto, un tema se considera «por reforzar». */
    public const UMBRAL_REFUERZO = 60.0;

    /**
     * Un tema con dos respuestas no dice nada: basta un despiste para mandarlo
     * al 50 %. Se exige un mínimo de evidencia antes de afirmar nada sobre él.
     */
    public const MINIMO_RESPUESTAS = 3;

    /**
     * Dominio por tema de un estudiante, del más flojo al más sólido.
     *
     * @return Collection<int, array{topic:string, total:int, correctas:int, percentage:float}>
     */
    public function porEstudiante(string $studentUserId, int $limite = 10): Collection
    {
        return $this->agregar(
            fn ($q) => $q->where('ea.student_user_id', $studentUserId),
            $limite
        );
    }

    /**
     * Dominio por tema de un conjunto de estudiantes — el grupo que alcanza un
     * docente. Es un agregado sin nombres: [173] solo le concede «métricas
     * agregadas» y «reportes anónimos», y un listado por alumno no lo sería.
     *
     * @param  \Illuminate\Database\Query\Builder|array<int,string>  $estudiantes
     * @return Collection<int, array{topic:string, total:int, correctas:int, percentage:float}>
     */
    public function porEstudiantes($estudiantes, int $limite = 10): Collection
    {
        return $this->agregar(
            fn ($q) => $q->whereIn('ea.student_user_id', $estudiantes),
            $limite
        );
    }

    /**
     * Dominio por tema de toda la institución. Para el admin, que responde por
     * el centro entero; el aislamiento lo sigue poniendo `TenantScoped`.
     *
     * @return Collection<int, array{topic:string, total:int, correctas:int, percentage:float}>
     */
    public function porTodos(int $limite = 10): Collection
    {
        return $this->agregar(fn ($q) => $q, $limite);
    }

    /**
     * @param  \Closure  $acotar  filtro que decide de quién se agregan las respuestas
     * @return Collection<int, array{topic:string, total:int, correctas:int, percentage:float}>
     */
    private function agregar(\Closure $acotar, int $limite): Collection
    {
        $query = StudentAnswer::query()
            ->join('questions as q', 'q.id', '=', 'student_answers.question_id')
            ->join('exam_attempts as ea', 'ea.id', '=', 'student_answers.attempt_id')
            ->whereNotNull('ea.submitted_at')
            ->whereNotNull('q.topic_normalized')
            ->groupBy('q.topic_normalized')
            ->havingRaw('COUNT(*) >= ?', [self::MINIMO_RESPUESTAS])
            ->selectRaw(
                'q.topic_normalized,
                 MIN(q.topic) AS topic,
                 COUNT(*) AS total,
                 COUNT(*) FILTER (WHERE student_answers.is_correct) AS correctas'
            );

        $acotar($query);

        return $query->get()
            ->map(function ($fila) {
                $total     = (int) $fila->total;
                $correctas = (int) $fila->correctas;

                return [
                    'topic'      => (string) $fila->topic,
                    'total'      => $total,
                    'correctas'  => $correctas,
                    'percentage' => $total > 0 ? round($correctas / $total * 100, 2) : 0.0,
                ];
            })
            // El orden se hace en PHP y no en SQL porque `percentage` es un
            // cálculo derivado: repetirlo en un ORDER BY lo dejaría en dos
            // sitios que pueden divergir.
            ->sortBy('percentage')
            ->take($limite)
            ->values();
    }
}
