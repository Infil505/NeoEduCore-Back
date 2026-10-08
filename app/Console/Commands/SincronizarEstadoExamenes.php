<?php

namespace App\Console\Commands;

use App\Enums\ExamStatus;
use App\Jobs\NotificarExamenDisponible;
use App\Models\Exams\Exam;
use Illuminate\Console\Command;

/**
 * Abre y cierra los exámenes según sus fechas (corre cada minuto).
 *
 * - «Listo» (published) → «Abierto» (active) al llegar `available_from`, y
 *   avisa a los estudiantes en ese momento.
 * - «Abierto» (active) → «Cerrado» (completed) al pasar `available_until`.
 *
 * Corre sin tenant (CLI), así que recorre los exámenes de todos los centros.
 * Cada cambio va condicionado al estado actual: si el docente lo cambió a mano
 * entre la lectura y la escritura, no se pisa.
 */
class SincronizarEstadoExamenes extends Command
{
    protected $signature = 'exams:sincronizar-estados';

    protected $description = 'Abre y cierra los exámenes según su fecha de apertura y de cierre';

    public function handle(): int
    {
        $ahora = now();

        $porAbrir = Exam::withoutGlobalScope('tenant')
            ->where('status', ExamStatus::Published->value)
            ->whereNotNull('available_from')
            ->where('available_from', '<=', $ahora)
            ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>', $ahora))
            ->pluck('id');

        $abiertos = 0;
        foreach ($porAbrir as $id) {
            $cambiado = Exam::withoutGlobalScope('tenant')->whereKey($id)
                ->where('status', ExamStatus::Published->value)
                ->update(['status' => ExamStatus::Active->value, 'updated_at' => $ahora]);

            if ($cambiado) {
                NotificarExamenDisponible::dispatch($id);
                $abiertos++;
            }
        }

        $cerrados = Exam::withoutGlobalScope('tenant')
            ->where('status', ExamStatus::Active->value)
            ->whereNotNull('available_until')
            ->where('available_until', '<=', $ahora)
            ->update(['status' => ExamStatus::Completed->value, 'updated_at' => $ahora]);

        if ($abiertos || $cerrados) {
            $this->info("Exámenes abiertos: {$abiertos} · cerrados: {$cerrados}");
        }

        return self::SUCCESS;
    }
}