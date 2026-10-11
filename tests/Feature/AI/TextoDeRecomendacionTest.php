<?php

namespace Tests\Feature\AI;

use App\Models\AI\AiRecommendation;
use App\Support\TextoDeRecomendacion;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El modelo cierra el apartado del recurso con un bloque JSON para el sistema. Ese bloque no debe verse:
 * ni en lo que se guarda desde ahora ni en las recomendaciones que ya estaban guardadas con él.
 */
class TextoDeRecomendacionTest extends TestCase
{
    /** Tal cual lo vio el docente en pantalla (con el «`json» de una sola comilla y la lista anidada). */
    private const COMO_SE_VEIA = <<<'TXT'
Puedes usar juegos en línea que trabajen con fracciones y decimales, como "Khan Academy" o "Prodigy". También puedes hacer ejercicios en libros de matemáticas de tu nivel.

`json
{
"resources": [
{
"name": "Khan Academy",
"type": "sitio web",
"link": "https://www.khanacademy.org/math/arithmetic/fractions"
},
{
"name": "Prodigy",
"type": "juego educativo",
"link": "[enlace no permitido]"
}
]
}
TXT;

    public function test_quita_el_bloque_anidado_y_deja_la_prosa(): void
    {
        $limpio = TextoDeRecomendacion::sinBloquesJson(self::COMO_SE_VEIA);

        $this->assertStringContainsString('Puedes usar juegos en línea', $limpio);
        $this->assertStringContainsString('libros de matemáticas de tu nivel.', $limpio);
        $this->assertStringNotContainsString('{', $limpio);
        $this->assertStringNotContainsString('json', strtolower($limpio));
        $this->assertStringNotContainsString('enlace no permitido', $limpio);
    }

    public function test_quita_los_bloques_con_su_cerca_de_codigo(): void
    {
        $texto = "Mira esto:\n```json\n{\"title\": \"A\", \"url\": \"https://es.khanacademy.org/x\"}\n```\nY después sigue.";

        $this->assertSame("Mira esto:\n\nY después sigue.", TextoDeRecomendacion::sinBloquesJson($texto));
    }

    public function test_las_llaves_dentro_de_comillas_no_rompen_el_conteo(): void
    {
        $texto = 'Antes {"nota": "usa } y { con cuidado", "n": 1} después';

        $this->assertSame('Antes  después', TextoDeRecomendacion::sinBloquesJson($texto));
    }

    public function test_solo_se_quita_lo_que_es_json(): void
    {
        $prosa = 'El conjunto {1, 2, 3} tiene tres elementos y {sin cerrar queda igual.';

        $this->assertSame($prosa, TextoDeRecomendacion::sinBloquesJson($prosa));
    }

    public function test_los_bloques_se_devuelven_para_extraer_el_recurso(): void
    {
        $bloques = TextoDeRecomendacion::bloquesJson(self::COMO_SE_VEIA);

        $this->assertCount(1, $bloques);
        $this->assertSame('Khan Academy', $bloques[0]['datos']['resources'][0]['name']);
    }

    public function test_lo_ya_guardado_se_lee_limpio_y_el_dato_no_se_toca(): void
    {
        $rec = new AiRecommendation();
        $rec->setRawAttributes(['id' => (string) Str::uuid(), 'recommendation_text' => self::COMO_SE_VEIA]);

        $this->assertStringNotContainsString('{', $rec->recommendation_text);
        $this->assertStringContainsString('Puedes usar juegos en línea', $rec->recommendation_text);
        $this->assertSame(self::COMO_SE_VEIA, $rec->getAttributes()['recommendation_text']);
        $this->assertStringNotContainsString('{', $rec->toArray()['recommendation_text'], 'Tampoco en la API.');
    }

    public function test_un_texto_que_era_solo_json_no_queda_vacio(): void
    {
        $rec = new AiRecommendation();
        $rec->setRawAttributes(['id' => (string) Str::uuid(), 'recommendation_text' => "```json\n{\"a\": 1}\n```"]);

        $this->assertSame('Sin detalle disponible.', $rec->recommendation_text);
    }
}
