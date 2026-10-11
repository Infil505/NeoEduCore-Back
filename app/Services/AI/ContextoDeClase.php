<?php

namespace App\Services\AI;

use App\Models\Exams\Exam;
use App\Services\Academic\TopicMasteryService;
use App\Services\Exams\ExamGroupAnalysisService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el asistente del DOCENTE sabe de su clase: cómo le fue al grupo en los
 * últimos exámenes, qué preguntas se fallaron más (candidatas a refuerzo), qué
 * temas flojean y cuántos alumnos quedaron por debajo del mínimo.
 *
 * Sale de `ExamGroupAnalysisService` (el mismo análisis que ve en el informe), de
 * modo que el asistente y la pantalla nunca se contradicen.
 *
 * **Qué NO entra, a propósito:** nombres ni ids de estudiantes (solo cifras del
 * grupo; las listas nominales se quedan en el análisis), ni la respuesta correcta
 * de las preguntas abiertas. Del error más repetido sí entra el texto, saneado.
 *
 * **Seguridad:** enunciados, temas y respuestas de alumnos son texto de terceros:
 * entran saneados y el prompt los trata como datos.
 *
 * **Alcance:** un docente ve los exámenes que creó (igual que `ReportController`);
 * el administrador, los de su centro. Filtra por institución a mano.
 */
class ContextoDeClase
{
    /** Preguntas «más falladas» por examen en el resumen general. */
    private const PREGUNTAS = 3;

    /** Preguntas en el examen sobre el que se conversa. */
    private const PREGUNTAS_EN_FOCO = 6;

    private const TEMAS_FLOJOS = 4;

    public function __construct(
        private readonly ExamGroupAnalysisService $analisis,
        private readonly TopicMasteryService $temas,
        private readonly AiInputSanitizer $sanitizer,
    ) {
    }

    /**
     * @param  string|null  $docenteId  null = administrador (todo el centro)
     * @param  Exam|null    $foco       examen del que se habla; entra con más detalle
     */
    public function para(string $institutionId, ?string $docenteId, ?Exam $foco = null): string
    {
        $examenes = $this->recientes($institutionId, $docenteId, (int) config('openai.docente.exams', 4), $foco);

        if ($examenes->isEmpty()) {
            return 'Aún no hay exámenes entregados en tu clase: no tienes resultados que analizar.';
        }

        $huellas = DB::table('exam_attempts')
            ->where('institution_id', $institutionId)
            ->whereIn('exam_id', $examenes->pluck('id'))
            ->groupBy('exam_id')
            ->selectRaw('exam_id, COUNT(*) AS n, MAX(updated_at) AS ultimo')
            ->get()->keyBy('exam_id');

        $bloques = $examenes->map(function (Exam $e) use ($huellas, $foco) {
            $enFoco = $foco !== null && $foco->id === $e->id;
            $h = $huellas->get($e->id);

            return Cache::remember(
                'ai:docente:examen:' . $e->id . ':' . ($enFoco ? 'foco' : 'resumen') . ':'
                    . md5(json_encode([$h->n ?? 0, $h->ultimo ?? null, (string) $e->updated_at])),
                600,
                fn () => $this->bloque($this->analisis->analizar($e), $enFoco)
            );
        })->implode("\n");

        $flojos = $this->temasFlojos($docenteId);

        return "Resultados de la clase (cifras del grupo, sin nombres de estudiantes):\n{$bloques}"
            . ($flojos !== '' ? "\n{$flojos}" : '');
    }

    /** @return \Illuminate\Support\Collection<int,Exam> los más recientes con intentos entregados; el examen en foco, siempre primero */
    private function recientes(string $institutionId, ?string $docenteId, int $limite, ?Exam $foco)
    {
        $ids = DB::table('exam_attempts as ea')
            ->join('exams as e', 'e.id', '=', 'ea.exam_id')
            ->where('e.institution_id', $institutionId)
            ->when($docenteId !== null, fn ($q) => $q->where('e.created_by_teacher_id', $docenteId))
            ->whereNotNull('ea.submitted_at')
            ->when($foco !== null, fn ($q) => $q->where('e.id', '!=', $foco->id))
            ->groupBy('e.id')
            ->orderByRaw('MAX(ea.submitted_at) DESC')
            ->limit(max(1, $limite - ($foco !== null ? 1 : 0)))
            ->pluck('e.id')
            ->all();

        if ($foco !== null) {
            array_unshift($ids, $foco->id);
        }

        $porId = Exam::query()->whereIn('id', $ids)->with('subject:id,name')->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $porId->get($id))->filter()->values();
    }

    /** @param array<string,mixed> $a salida de `ExamGroupAnalysisService::analizar` */
    private function bloque(array $a, bool $enFoco): string
    {
        $c = $a['coverage'];
        $pasa = $a['passing_percentage'];

        $lineas = [sprintf(
            '- %s, «%s»%s: presentaron %d de %d, %s%s. Sin presentar: %d. Por debajo del mínimo (%s %%): %d alumnos.',
            $this->limpio($a['exam']['subject'] ?? null, 40) ?: 'Materia',
            $this->limpio($a['exam']['title'] ?? null, 70) ?: 'Examen',
            $enFoco ? ' (el examen del que habla)' : '',
            $c['presented'],
            $c['assigned'],
            $c['average_pct'] !== null ? "promedio {$c['average_pct']} %" : 'sin promedio aún',
            $c['passing_rate_pct'] !== null ? ", aprobó el {$c['passing_rate_pct']} %" : '',
            $c['not_presented'],
            $pasa,
            count($a['students_to_attend'])
        )];

        if ($c['needs_review'] > 0) {
            $lineas[] = "  Hay {$c['needs_review']} respuestas abiertas sin calificar: las notas son provisionales.";
        }

        if ($c['presented'] === 0) {
            return implode("\n", $lineas);
        }

        // Las preguntas con más fallos; solo cuentan las que tienen respuestas suficientes.
        $preguntas = collect($a['hardest_questions'])
            ->take($enFoco ? self::PREGUNTAS_EN_FOCO : self::PREGUNTAS)
            ->map(fn (array $h) => collect($a['questions'])->firstWhere('id', $h['question_id']))
            ->filter();

        if ($preguntas->isNotEmpty()) {
            $lineas[] = '  Preguntas más falladas (candidatas a refuerzo):';
            foreach ($preguntas as $p) {
                $lineas[] = sprintf(
                    '   · P%d «%s»%s: acertó el %s %% (%d de %d)%s%s',
                    $p['number'],
                    $this->limpio($p['text'], 120),
                    $p['topic'] ? ' (tema ' . $this->limpio($p['topic'], 50) . ')' : '',
                    $p['correct_rate'],
                    $p['correct'],
                    $p['answered'],
                    $p['indicator'] ? '. Indicador: ' . $this->limpio($p['indicator'], 80) : '',
                    $this->confusion($p)
                );
            }
        }

        $flojos = collect($a['topics'])
            ->filter(fn (array $t) => $t['correct_rate'] !== null && $t['correct_rate'] < $pasa && $t['answered'] >= 3)
            ->take(self::TEMAS_FLOJOS)
            ->map(fn (array $t) => $this->limpio($t['topic'], 60) . " ({$t['correct_rate']} %)")
            ->implode(', ');

        if ($flojos !== '') {
            $lineas[] = "  Temas flojos en este examen: {$flojos}.";
        }

        if ($enFoco && $a['groups'] !== []) {
            $lineas[] = '  Por aula: ' . collect($a['groups'])->map(fn (array $g) => sprintf(
                '%s %s (%d/%d presentaron)',
                $this->limpio($g['name'], 40),
                $g['average_pct'] !== null ? "{$g['average_pct']} %" : 'sin notas',
                $g['presented'],
                $g['assigned']
            ))->implode('; ') . '.';
        }

        return implode("\n", $lineas);
    }

    /** « Lo más elegido sin ser correcto: «X» (60 %).» o « Error repetido: «y» (2 alumnos).» */
    private function confusion(array $p): string
    {
        if ($p['options'] !== []) {
            $o = collect($p['options'])->where('is_correct', false)->sortByDesc('chosen')->first();

            return $o && $o['chosen'] > 0
                ? '. Opción incorrecta más elegida: «' . $this->limpio($o['text'], 80) . "» ({$o['share_pct']} %)"
                : '';
        }

        $comun = $p['common_wrong_answers'][0] ?? null;

        return $comun !== null
            ? '. Error repetido: «' . $this->limpio($comun['answer'], 80) . "» ({$comun['count']} alumnos)"
            : '';
    }

    /** Temas flojos sumando TODOS los exámenes de la clase (con evidencia suficiente). */
    private function temasFlojos(?string $docenteId): string
    {
        $dominio = $docenteId === null
            ? $this->temas->porTodos(self::TEMAS_FLOJOS * 2)
            : $this->temas->porEstudiantes(
                DB::table('group_students as gs')
                    ->select('gs.student_user_id')
                    ->join('teacher_assignments as ta', 'ta.group_id', '=', 'gs.group_id')
                    ->whereNull('gs.left_at')
                    ->where('ta.teacher_user_id', $docenteId)
                    ->where('ta.institution_id', app('tenant_id')),
                self::TEMAS_FLOJOS * 2
            );

        $flojos = $dominio
            ->filter(fn (array $t) => $t['percentage'] < TopicMasteryService::UMBRAL_REFUERZO)
            ->take(self::TEMAS_FLOJOS);

        if ($flojos->isEmpty()) {
            return '';
        }

        return "Temas más flojos de toda la clase (todos los exámenes, para reforzar):\n"
            . $flojos->map(fn (array $t) => sprintf(
                '- %s: %s %% de acierto (%d de %d respuestas)',
                $this->limpio($t['topic'], 60) ?: 'Tema',
                round($t['percentage']),
                $t['correctas'],
                $t['total']
            ))->implode("\n");
    }

    private function limpio(?string $texto, int $max): string
    {
        return $this->sanitizer->paraPrompt($texto, $max);
    }
}
