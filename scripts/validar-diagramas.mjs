/**
 * Valida los bloques Mermaid de un Markdown con el parser REAL de Mermaid,
 * sin navegador.
 *
 *   npm i            # instala mermaid + jsdom
 *   npm run validar-diagramas
 *   node scripts/validar-diagramas.mjs docs/OTRO.md
 *
 * Existe porque un diagrama con un error de sintaxis no rompe nada al hacer
 * commit: simplemente aparece en blanco al renderizarlo, y eso se descubre
 * tarde. La primera pasada de esta comprobacion encontro 4 errores que a ojo
 * parecian validos (`PK_FK` no existe, se escribe `PK, FK`).
 */
import fs from 'node:fs';
import { JSDOM } from 'jsdom';

const archivo = process.argv[2] ?? 'docs/DIAGRAMAS.md';

if (!fs.existsSync(archivo)) {
  console.error(`No existe el archivo: ${archivo}`);
  process.exit(1);
}

const dom = new JSDOM('<!doctype html><html><body></body></html>');
globalThis.window = dom.window;
globalThis.document = dom.window.document;

// En Node 24 `navigator` solo tiene *getter*: hay que definirlo, no asignarlo.
Object.defineProperty(globalThis, 'navigator', {
  value: dom.window.navigator,
  configurable: true,
  writable: true,
});

// Mermaid espera DOMPurify en el entorno; para parsear basta con un doble.
globalThis.DOMPurify = { sanitize: (s) => s, addHook: () => {}, setConfig: () => {} };

const { default: mermaid } = await import('mermaid');
mermaid.initialize({ startOnLoad: false, securityLevel: 'loose' });

const md = fs.readFileSync(archivo, 'utf8');
const bloques = [...md.matchAll(/```mermaid\r?\n([\s\S]*?)```/g)];

let fallos = 0;

for (const [i, m] of bloques.entries()) {
  const linea = md.slice(0, m.index).split('\n').length;
  try {
    await mermaid.parse(m[1]);
  } catch (e) {
    fallos++;
    console.error(`  FALLA #${i + 1} (linea ${linea}): ${String(e.message).split('\n')[0]}`);
  }
}

console.log(`${archivo}: ${bloques.length} diagramas, ${fallos} con error`);
process.exit(fallos ? 1 : 0);
