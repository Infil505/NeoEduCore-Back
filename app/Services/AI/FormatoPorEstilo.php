<?php

namespace App\Services\AI;

use App\Enums\LearningStyle;
use App\Models\Academic\StudyResource;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;

/**
 * Cómo debe organizar el tutor su respuesta según el estilo de aprendizaje (O2).
 *
 * Antes el estilo solo cambiaba el tono del prompt y la respuesta era siempre
 * el mismo bloque de texto. Ahora decide la forma —pasos y esquemas para
 * `visual`, texto para leer en voz alta para `auditivo`, estructura con
 * definiciones para `lector`— y viaja además en la respuesta como
 * `presentation`, para que el frontend pueda, por ejemplo, leerla en voz alta.
 *
 * Igual que `RegistroPorGrado`: lo usan el chat y el diagnóstico, y los textos
 * están en `config/openai.php` porque los afina el profesorado.
 */
class FormatoPorEstilo
{
    /** Instrucción de formato lista para concatenar al prompt, o null sin estilo. */
    public function para(?LearningStyle $estilo): ?string
    {
        if ($estilo === null) {
            return null;
        }

        $texto = config("openai.tutor.formato.{$estilo->value}");

        return is_string($texto) && $texto !== '' ? $texto : null;
    }

    /** Lo que se devuelve al frontend en `presentation`. */
    public function presentacion(?LearningStyle $estilo): ?string
    {
        return $estilo?->value;
    }

    /**
     * El campo `video` de la respuesta, si el estilo del alumno es de los que lo
     * reciben (`openai.tutor.video_para_estilos`). Null si no toca o no hay
     * ninguno que ofrecer. Siempre es de un examen concreto: sin examen, null.
     *
     * Orden: el vídeo del docente en el examen (`video_url`, luego los vídeos de
     * `support_resources`) y, si no hay o ya no está disponible, uno del
     * catálogo del centro acorde al grado del alumno
     * (`recursoDeApoyo(..., soloVideo: true)`). Todo se comprueba antes con
     * `EnlaceDisponible`: nunca sale un enlace roto.
     *
     * Ningún enlace lo propone el modelo: con menores de 6 a 12 años no se
     * entrega contenido que nadie haya revisado.
     */
    public function videoPara(?LearningStyle $estilo, ?Exam $exam, ?Student $alumno = null): ?array
    {
        if (!$this->recibeVideo($estilo) || $exam === null) {
            return null;
        }

        $video = $this->recursoDeApoyo($estilo, $exam, $alumno, soloVideo: true);

        if ($video === null) {
            return null;
        }

        return [
            'url'        => $video['url'],
            'title'      => $video['title'],
            'source'     => $video['source'],
            'exam_id'    => $exam->id,
            'exam_title' => $exam->title,
        ];
    }

    /**
     * El enlace que el tutor ofrece como «recurso», con la misma forma que uno
     * del catálogo (`title`, `type`, `url`…), o null si no hay ninguno que dar.
     * Cada candidato se comprueba con `EnlaceDisponible` y se salta el roto.
     *
     * 1. **Lo del docente** (`exams.support_resources` más el `video_url`
     *    clásico). Se prefiere el tipo que encaja con el estilo —vídeo para
     *    `visual` y `auditivo`, texto para el resto— y, si no hay, cualquiera.
     *    Con `$soloVideo`, solo vídeos.
     * 2. **Si el estilo necesita vídeo y el docente no dejó ninguno vivo**, uno
     *    del catálogo del centro, acorde a la edad (`videoDelCatalogo()`).
     *
     * Para quien no necesita vídeo y el docente no dejó nada, devuelve null y el
     * llamador sigue con su cadena habitual de recursos.
     *
     * La URL la eligió una persona y se valida al guardar; el modelo no
     * interviene.
     */
    public function recursoDeApoyo(
        ?LearningStyle $estilo,
        ?Exam $exam,
        ?Student $alumno = null,
        bool $soloVideo = false
    ): ?array {
        if ($exam === null) {
            return null;
        }

        $quiereVideo = $soloVideo || $this->recibeVideo($estilo);
        $enlaces     = app(EnlaceDisponible::class);

        $candidatos = collect((array) $exam->support_resources)
            ->filter(fn ($r) => is_array($r) && !empty($r['url']))
            ->map(fn (array $r) => [
                'type'  => ($r['type'] ?? 'text') === 'video' ? 'video' : 'text',
                'url'   => (string) $r['url'],
                'title' => (string) ($r['title'] ?? ''),
            ]);

        if ($exam->video_url && !$candidatos->contains('url', $exam->video_url)) {
            // El vídeo clásico va primero: es el que el docente marcó como principal.
            $candidatos->prepend(['type' => 'video', 'url' => $exam->video_url, 'title' => '']);
        }

        if ($soloVideo) {
            $candidatos = $candidatos->where('type', 'video');
        }

        // Preferido primero (orden estable: conserva el que puso el docente).
        $preferido  = $quiereVideo ? 'video' : 'text';
        $candidatos = $candidatos
            ->values()
            ->map(fn (array $c, int $i) => $c + ['_orden' => ($c['type'] === $preferido ? 0 : 1000) + $i])
            ->sortBy('_orden')
            ->values();

        foreach ($candidatos as $c) {
            if ($enlaces->disponible($c['url'])) {
                return [
                    'title'              => $c['title'] !== '' ? $c['title'] : 'Material de apoyo: ' . $exam->title,
                    'type'               => $c['type'] === 'video' ? 'video' : 'article',
                    'url'                => $c['url'],
                    'difficulty'         => null,
                    'estimated_duration' => null,
                    'language'           => 'es',
                    'source'             => 'teacher',
                ];
            }
        }

        return $quiereVideo ? $this->videoDelCatalogo($exam, $alumno) : null;
    }

    /**
     * Vídeo del catálogo del centro para un alumno que lo necesita y cuyo
     * examen no trae uno vivo.
     *
     * **Acorde a la edad**: el grado del alumno (o, si su perfil no lo tiene, el
     * del examen) tiene que caer dentro de `grade_min`/`grade_max` del recurso;
     * un vídeo sin rango declarado vale como genérico pero va después de uno
     * que sí lo declara. A diferencia del recurso de texto, aquí **no se afloja
     * el grado** si no hay ninguno: preferimos no dar vídeo a darle a un niño de
     * 7 años uno pensado para 12.
     *
     * Solo los que el alumno puede abrir (enviados a un aula donde está
     * matriculado ahora), de la materia del examen o sin materia, y que sigan
     * disponibles: se prueban en orden hasta encontrar uno vivo.
     */
    private function videoDelCatalogo(Exam $exam, ?Student $alumno): ?array
    {
        $grado = $alumno?->grade ?? $exam->grade;

        if ($grado === null || $alumno === null) {
            return null;
        }

        $candidatos = StudyResource::query()
            ->where('institution_id', $exam->institution_id)
            ->where('resource_type', 'video')
            ->where(fn ($q) => $q->where('subject_id', $exam->subject_id)->orWhereNull('subject_id'))
            ->where(fn ($q) => $q->whereNull('grade_min')->orWhere('grade_min', '<=', $grado))
            ->where(fn ($q) => $q->whereNull('grade_max')->orWhere('grade_max', '>=', $grado))
            ->whereHas('groups', fn ($g) => $g->whereIn(
                'groups.id',
                DB::table('group_students')
                    ->select('group_id')
                    ->where('institution_id', $alumno->institution_id)
                    ->where('student_user_id', $alumno->user_id)
                    ->whereNull('left_at')
            ))
            // Primero la materia del examen, luego el rango de grado declarado,
            // luego el nivel básico (la rama de refuerzo), luego el más reciente.
            ->orderByRaw('CASE WHEN subject_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN grade_min IS NULL AND grade_max IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw("CASE WHEN difficulty = 'basic' THEN 0 WHEN difficulty IS NULL THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $enlaces = app(EnlaceDisponible::class);

        foreach ($candidatos as $r) {
            if ($enlaces->disponible($r->url)) {
                return [
                    'title'              => $r->title,
                    'type'               => 'video',
                    'url'                => $r->url,
                    'difficulty'         => $r->difficulty,
                    'estimated_duration' => $r->estimated_duration,
                    'language'           => $r->language ?? 'es',
                    'source'             => 'catalog',
                ];
            }
        }

        return null;
    }

    /** Para no buscar el examen cuando el estilo no recibe vídeo. */
    public function recibeVideo(?LearningStyle $estilo): bool
    {
        return $estilo !== null
            && in_array($estilo->value, (array) config('openai.tutor.video_para_estilos', []), true);
    }
}
