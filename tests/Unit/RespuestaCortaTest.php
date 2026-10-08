<?php

namespace Tests\Unit;

use App\Services\Exams\ExamGradingService;
use PHPUnit\Framework\TestCase;

/**
 * La respuesta corta se califica sola cuando coincide con la esperada sin
 * importar mayúsculas, tildes, signos ni saltos de línea.
 */
class RespuestaCortaTest extends TestCase
{
    public function test_ignora_mayusculas_tildes_y_signos(): void
    {
        $esperada = ExamGradingService::normalizarTexto('Disminución de la población indígena - Pérdida de tierras');

        $this->assertSame($esperada, ExamGradingService::normalizarTexto("Disminución de la población indígena.\nPérdida de tierras."));
        $this->assertSame($esperada, ExamGradingService::normalizarTexto('  disminucion de la poblacion INDIGENA, perdida de tierras '));
    }

    public function test_respuestas_distintas_no_coinciden(): void
    {
        $this->assertNotSame(
            ExamGradingService::normalizarTexto('Pérdida de tierras'),
            ExamGradingService::normalizarTexto('Disminución de la población indígena - Pérdida de tierras'),
        );
        $this->assertSame('', ExamGradingService::normalizarTexto(null));
    }
}
