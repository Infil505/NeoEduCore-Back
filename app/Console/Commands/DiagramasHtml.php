<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Genera `docs/diagramas.html` a partir de `docs/DIAGRAMAS.md`.
 *
 * **Por qué existe.** Los diagramas se escriben una sola vez, en Mermaid, dentro
 * del Markdown: es lo que se lee en el repositorio y en GitHub. Pero ahí se
 * renderizan encogidos para caber en la columna de texto, y un ERD o un diagrama
 * de clases se vuelve ilegible. Esta página los saca a ancho completo, con
 * desplazamiento horizontal y zoom.
 *
 * Es el mismo patrón que `openapi:generate` y el generador de la colección de
 * Postman: el HTML es un **artefacto derivado**, nunca una segunda copia que
 * mantener. Si se edita, se pierde en la siguiente ejecución — hay que tocar el
 * Markdown.
 *
 *   php artisan diagramas:html
 */
class DiagramasHtml extends Command
{
    protected $signature = 'diagramas:html
        {--source=docs/DIAGRAMAS.md}
        {--output=docs/diagramas.html}';

    protected $description = 'Genera la versión HTML navegable de los diagramas desde el Markdown';

    public function handle(): int
    {
        $origen  = base_path($this->option('source'));
        $destino = base_path($this->option('output'));

        if (!is_file($origen)) {
            $this->error("No existe {$origen}");
            return self::FAILURE;
        }

        $markdown = file_get_contents($origen);

        // Los bloques ```mermaid se apartan ANTES de convertir: CommonMark los
        // dejaría como <pre><code class="language-mermaid">, y el renderizador
        // de Mermaid busca <pre class="mermaid">. Se sustituyen por un
        // marcador opaco y se reinyectan ya envueltos en su figura.
        $diagramas = [];
        $markdown = preg_replace_callback(
            '/^```mermaid\R(.*?)^```$/ms',
            function (array $m) use (&$diagramas): string {
                $i = count($diagramas);
                $diagramas[] = trim($m[1]);
                return "\n\nMERMAIDMARCADOR{$i}ZZ\n\n";
            },
            $markdown
        );

        $entorno = new Environment([
            'html_input'         => 'allow',
            'allow_unsafe_links' => false,
        ]);
        $entorno->addExtension(new CommonMarkCoreExtension());
        $entorno->addExtension(new TableExtension());

        $html = (string) (new MarkdownConverter($entorno))->convert($markdown);

        $html = preg_replace_callback(
            '/<p>MERMAIDMARCADOR(\d+)ZZ<\/p>/',
            fn (array $m) => $this->figura($diagramas[(int) $m[1]], (int) $m[1] + 1),
            $html
        );

        // El <h1> del Markdown pasa a ser la cabecera de la página.
        $titulo = 'NeoEduCore — Diagramas del sistema';
        if (preg_match('/<h1>(.*?)<\/h1>/s', $html, $m)) {
            $titulo = strip_tags($m[1]);
            $html = preg_replace('/<h1>.*?<\/h1>/s', '', $html, 1);
        }

        file_put_contents($destino, $this->pagina($titulo, $html, count($diagramas)));

        $this->info(sprintf(
            'OK: %d diagramas en %s',
            count($diagramas),
            $this->option('output')
        ));

        return self::SUCCESS;
    }

    /**
     * Cada diagrama va en su propio lienzo desplazable, con zoom independiente.
     *
     * El zoom se aplica con `zoom` sobre el contenedor y no con `transform`
     * sobre el SVG: `transform` no reserva espacio, así que la figura ampliada
     * se saldría del flujo en vez de generar barra de desplazamiento. Además,
     * actuar sobre el contenedor evita depender de cuándo Mermaid sustituye el
     * `<pre>` por el `<svg>`.
     */
    private function figura(string $mermaid, int $n): string
    {
        $codigo = htmlspecialchars($mermaid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
        <figure class="figura" id="figura-{$n}">
          <div class="figura__barra">
            <span class="figura__n">Diagrama {$n}</span>
            <div class="figura__acciones">
              <button type="button" class="btn" data-zoom="-1" aria-label="Reducir el diagrama {$n}">−</button>
              <output class="btn btn--valor" data-zoom-valor>100%</output>
              <button type="button" class="btn" data-zoom="1" aria-label="Ampliar el diagrama {$n}">+</button>
              <button type="button" class="btn" data-zoom="0">Ajustar</button>
              <button type="button" class="btn" data-ampliar aria-pressed="false">Pantalla completa</button>
            </div>
          </div>
          <div class="figura__lienzo">
            <pre class="mermaid">{$codigo}</pre>
          </div>
        </figure>
        HTML;
    }

    private function pagina(string $titulo, string $cuerpo, int $total): string
    {
        $tituloEsc = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
        $css = $this->css();
        $js  = $this->js();

        return <<<HTML
        <title>{$tituloEsc}</title>
        <style>{$css}</style>

        <header class="cabecera">
          <p class="eyebrow">Documentación técnica · derivada del código</p>
          <h1>{$tituloEsc}</h1>
          <p class="cabecera__sub">{$total} diagramas · rama <code>Darwin</code> · 8 de agosto de 2026</p>
          <p class="aviso aviso--nota">
            Generado con <code>php artisan diagramas:html</code> desde
            <code>docs/DIAGRAMAS.md</code>. Para cambiar un diagrama se edita el Markdown,
            no esta página.
          </p>
        </header>

        <main class="prosa">{$cuerpo}</main>

        <script>{$js}</script>
        HTML;
    }

    private function css(): string
    {
        return <<<'CSS'
        :root {
          color-scheme: light dark;
          /* Neutros con sesgo frío hacia el acento: un gris puro leería como no elegido. */
          --fondo: #fbfbfd;
          --fondo-alt: #f2f3f7;
          --tinta: #16181f;
          --tinta-suave: #565b6b;
          --borde: #d9dce6;
          --acento: #2f4f8f;
          --acento-suave: #eaeef8;
          --aviso: #8a5200;
          --aviso-fondo: #fdf4e3;
          --ancho-prosa: 74ch;
        }
        @media (prefers-color-scheme: dark) {
          :root {
            --fondo: #0f1117;
            --fondo-alt: #171a22;
            --tinta: #e6e8ef;
            --tinta-suave: #9aa1b4;
            --borde: #2a2f3c;
            --acento: #8fadea;
            --acento-suave: #1b2540;
            --aviso: #e0a758;
            --aviso-fondo: #2a2113;
          }
        }
        :root[data-theme="dark"] {
          --fondo: #0f1117; --fondo-alt: #171a22; --tinta: #e6e8ef;
          --tinta-suave: #9aa1b4; --borde: #2a2f3c; --acento: #8fadea;
          --acento-suave: #1b2540; --aviso: #e0a758; --aviso-fondo: #2a2113;
        }
        :root[data-theme="light"] {
          --fondo: #fbfbfd; --fondo-alt: #f2f3f7; --tinta: #16181f;
          --tinta-suave: #565b6b; --borde: #d9dce6; --acento: #2f4f8f;
          --acento-suave: #eaeef8; --aviso: #8a5200; --aviso-fondo: #fdf4e3;
        }

        body {
          background: var(--fondo);
          color: var(--tinta);
          font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
          font-size: 16px;
          line-height: 1.65;
          margin: 0;
          padding: 0 1.25rem 6rem;
          -webkit-text-size-adjust: 100%;
        }

        .cabecera, .prosa { max-width: var(--ancho-prosa); margin-inline: auto; }
        .cabecera { padding-top: 3.5rem; }
        .eyebrow {
          font-size: .75rem; letter-spacing: .09em; text-transform: uppercase;
          color: var(--tinta-suave); margin: 0 0 .75rem;
        }

        /* Serif para los titulares: registro académico, que es el destino del documento. */
        h1, h2, h3 {
          font-family: ui-serif, Georgia, "Iowan Old Style", "Times New Roman", serif;
          font-weight: 600; text-wrap: balance; line-height: 1.2;
        }
        h1 { font-size: clamp(1.9rem, 1.4rem + 2.2vw, 2.7rem); margin: 0 0 .5rem; letter-spacing: -.015em; }
        h2 {
          font-size: 1.6rem; margin: 3.5rem 0 1rem;
          padding-bottom: .4rem; border-bottom: 2px solid var(--borde);
        }
        h3 { font-size: 1.18rem; margin: 2.25rem 0 .6rem; }
        .cabecera__sub { color: var(--tinta-suave); margin: 0 0 1.5rem; }

        p, li { max-width: var(--ancho-prosa); }
        a { color: var(--acento); text-underline-offset: .2em; }
        a:focus-visible, .btn:focus-visible {
          outline: 2px solid var(--acento); outline-offset: 2px; border-radius: 3px;
        }

        code {
          font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
          font-size: .875em; background: var(--fondo-alt);
          padding: .12em .38em; border-radius: 4px;
          border: 1px solid var(--borde);
        }
        pre code { border: 0; background: none; padding: 0; }
        pre:not(.mermaid) {
          background: var(--fondo-alt); border: 1px solid var(--borde);
          border-radius: 8px; padding: 1rem; overflow-x: auto; font-size: .875rem;
        }

        blockquote {
          margin: 1.5rem 0; padding: .9rem 1.1rem;
          background: var(--aviso-fondo); border-left: 3px solid var(--aviso);
          border-radius: 0 6px 6px 0;
        }
        blockquote > :first-child { margin-top: 0; }
        blockquote > :last-child { margin-bottom: 0; }

        .aviso { font-size: .9rem; color: var(--tinta-suave); }
        .aviso--nota {
          background: var(--acento-suave); border: 1px solid var(--borde);
          border-radius: 8px; padding: .8rem 1rem;
        }

        /* Las tablas son datos: cifras alineadas y desplazamiento propio. */
        .tabla-scroll { overflow-x: auto; margin: 1.5rem 0; }
        table {
          border-collapse: collapse; width: 100%; font-size: .9rem;
          font-variant-numeric: tabular-nums;
        }
        th, td {
          border: 1px solid var(--borde); padding: .5rem .7rem;
          text-align: left; vertical-align: top;
        }
        th { background: var(--fondo-alt); font-weight: 600; }

        /* ---- Figuras: rompen la columna de texto ---- */
        .figura {
          --z: 1;
          margin: 2rem calc((var(--ancho-prosa) - min(96vw, 1500px)) / 2);
          max-width: min(96vw, 1500px);
          width: min(96vw, 1500px);
          border: 1px solid var(--borde); border-radius: 10px;
          background: var(--fondo-alt); overflow: hidden;
        }
        @media (max-width: 900px) {
          .figura { margin-inline: 0; width: 100%; max-width: 100%; }
        }
        .figura__barra {
          display: flex; align-items: center; justify-content: space-between;
          gap: 1rem; flex-wrap: wrap;
          padding: .5rem .75rem; border-bottom: 1px solid var(--borde);
          background: var(--fondo);
        }
        .figura__n {
          font-size: .72rem; letter-spacing: .08em; text-transform: uppercase;
          color: var(--tinta-suave);
        }
        .figura__acciones { display: flex; gap: .3rem; align-items: center; }
        .btn {
          font: inherit; font-size: .8rem; line-height: 1;
          padding: .4rem .6rem; cursor: pointer;
          background: var(--fondo); color: var(--tinta);
          border: 1px solid var(--borde); border-radius: 6px;
        }
        .btn:hover { background: var(--acento-suave); border-color: var(--acento); }
        .btn--valor {
          cursor: default; min-width: 3.6em; text-align: center;
          font-variant-numeric: tabular-nums; color: var(--tinta-suave);
        }
        .btn--valor:hover { background: var(--fondo); border-color: var(--borde); }
        .btn[aria-pressed="true"] { background: var(--acento); color: var(--fondo); border-color: var(--acento); }

        .figura__lienzo {
          overflow: auto; padding: 1.25rem;
          background: var(--fondo);
          zoom: var(--z);
        }
        /* Sin esto Mermaid encoge el SVG al ancho del contenedor y el diagrama
           se vuelve ilegible: es exactamente el problema que resuelve la página. */
        .figura__lienzo svg { max-width: none !important; height: auto !important; }
        .figura .mermaid { margin: 0; text-align: left; }

        .figura--ampliada {
          position: fixed; inset: 0; z-index: 50;
          width: 100%; max-width: 100%; margin: 0; border-radius: 0;
          display: flex; flex-direction: column;
        }
        .figura--ampliada .figura__lienzo { flex: 1; }
        body.hay-ampliada { overflow: hidden; }

        @media (prefers-reduced-motion: reduce) {
          * { animation: none !important; transition: none !important; }
        }
        @media print {
          .figura__barra { display: none; }
          .figura { break-inside: avoid; border-color: #999; }
          body { padding: 0; }
        }
        CSS;
    }

    private function js(): string
    {
        return <<<'JS'
        (function () {
          var PASOS = [0.5, 0.67, 0.8, 1, 1.25, 1.5, 2, 2.5, 3];

          function fijar(figura, indice) {
            indice = Math.max(0, Math.min(PASOS.length - 1, indice));
            figura.dataset.paso = String(indice);
            figura.style.setProperty('--z', String(PASOS[indice]));
            var salida = figura.querySelector('[data-zoom-valor]');
            if (salida) salida.textContent = Math.round(PASOS[indice] * 100) + '%';
          }

          function pasoDe(figura) {
            var n = parseInt(figura.dataset.paso, 10);
            return isNaN(n) ? PASOS.indexOf(1) : n;
          }

          document.addEventListener('click', function (ev) {
            var boton = ev.target.closest('button');
            if (!boton) return;
            var figura = boton.closest('.figura');
            if (!figura) return;

            if (boton.hasAttribute('data-zoom')) {
              var delta = parseInt(boton.getAttribute('data-zoom'), 10);
              fijar(figura, delta === 0 ? PASOS.indexOf(1) : pasoDe(figura) + delta);
              return;
            }

            if (boton.hasAttribute('data-ampliar')) {
              var ampliada = figura.classList.toggle('figura--ampliada');
              document.body.classList.toggle('hay-ampliada', ampliada);
              boton.setAttribute('aria-pressed', ampliada ? 'true' : 'false');
              boton.textContent = ampliada ? 'Cerrar' : 'Pantalla completa';
            }
          });

          document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') return;
            var abierta = document.querySelector('.figura--ampliada');
            if (!abierta) return;
            var boton = abierta.querySelector('[data-ampliar]');
            if (boton) boton.click();
          });

          // Las tablas anchas se desplazan dentro de su caja, nunca la página.
          document.querySelectorAll('table').forEach(function (tabla) {
            if (tabla.parentElement && tabla.parentElement.classList.contains('tabla-scroll')) return;
            var caja = document.createElement('div');
            caja.className = 'tabla-scroll';
            tabla.parentNode.insertBefore(caja, tabla);
            caja.appendChild(tabla);
          });

          document.querySelectorAll('.figura').forEach(function (f) { fijar(f, PASOS.indexOf(1)); });
        })();
        JS;
    }
}
