<?php

namespace Tests\Unit\AI;

use App\Services\AI\AiOutputValidator;
use PHPUnit\Framework\TestCase;

/**
 * El filtro PII debe bloquear datos de contacto reales (email/teléfono/documento)
 * pero NO números normales de un tutor (resultados, rangos de años, fechas).
 */
class AiOutputValidatorTest extends TestCase
{
    private AiOutputValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        // Umbrales explícitos: son justo lo que este test comprueba, y así la
        // prueba sigue siendo unitaria (sin contenedor, sin config()).
        $this->validator = new AiOutputValidator(minLength: 5, maxLength: 4000);
    }

    // --- No debe bloquear (falsos positivos que antes ocurrían) ------------

    public function test_allows_normal_math_result(): void
    {
        $text = 'El resultado de 1234 x 5678 es 7006652. ¡Muy bien!';
        $this->assertNull($this->validator->validate($text));
    }

    public function test_allows_year_range(): void
    {
        $text = 'La Segunda Guerra Mundial ocurrió entre 1939-1945 aproximadamente.';
        $this->assertNull($this->validator->validate($text));
    }

    public function test_allows_practice_exercises_with_numbers(): void
    {
        $text = "Ejercicios:\n1) 45 + 78 = ?\n2) 900 - 250 = ?\n3) 12 x 12 = ?";
        $this->assertNull($this->validator->validate($text));
    }

    // --- Debe bloquear (datos de contacto reales) --------------------------

    public function test_blocks_email(): void
    {
        $text = 'Escríbeme a profesor@colegio.edu.co para más ayuda.';
        $this->assertNotNull($this->validator->validate($text));
    }

    public function test_blocks_phone_with_separators(): void
    {
        $text = 'Llámame al 310-123-4567 cuando quieras.';
        $this->assertNotNull($this->validator->validate($text));
    }

    public function test_blocks_international_phone(): void
    {
        $text = 'Mi número es +57 3101234567 por si acaso.';
        $this->assertNotNull($this->validator->validate($text));
    }

    public function test_blocks_long_digit_sequence(): void
    {
        $text = 'Tu documento es 1032456789 según el sistema.';
        $this->assertNotNull($this->validator->validate($text));
    }

    // --- Longitud ----------------------------------------------------------

    public function test_blocks_too_short_output(): void
    {
        $this->assertNotNull($this->validator->validate('ok'));
    }
}
