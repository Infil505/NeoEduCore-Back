// k6 — Pico de entregas de examen
//
// Reproduce el escenario que marca el techo de concurrencia del sistema: al
// cerrarse la ventana de un examen, media institución entrega casi a la vez.
// `stress_all.js` excluye deliberadamente el flujo de intentos, que es justo
// donde está el cuello de botella (ver ANALISIS_CONCURRENCIA.md).
//
// Cada iteración = un ciclo completo de entrega:
//   POST /exams/{id}/attempts/start   -> crea el intento
//   POST /exams/{id}/attempts/{a}/submit con las N respuestas
//
// Requiere la BD sembrada con LoadTestSeeder.
//
// Uso:
//   k6 run k6/exam_peak.js
//   k6 run -e VUS=50 -e DURATION=60s k6/exam_peak.js
//   k6 run -e MODE=ramp k6/exam_peak.js      (rampa para hallar el punto de saturación)

import http from 'k6/http';
import { check } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

// Uno o varios backends separados por coma. Varios procesos de `artisan serve`
// emulan los workers de Octane: `artisan serve` es monoproceso (en Windows
// PHP_CLI_SERVER_WORKERS no aplica), así que con una sola instancia los VUs se
// encolan y se mide el servidor, no el sistema.
const BASES = (__ENV.BASE_URLS || __ENV.BASE_URL || 'http://127.0.0.1:8000/api').split(',');
const BASE = BASES[0];
const PASS = __ENV.SEED_PASSWORD || 'Carga2026';
const ALUMNOS = Number(__ENV.ALUMNOS || 200);

/** Reparte los VUs entre los backends disponibles. */
function base() {
  return BASES[__VU % BASES.length];
}

const VUS = Number(__ENV.VUS || 20);
const DURATION = __ENV.DURATION || '30s';
const RAMP = (__ENV.MODE || '') === 'ramp';

const tStart = new Trend('t_start_attempt', true);
const tSubmit = new Trend('t_submit', true);
const tCiclo = new Trend('t_ciclo_completo', true);
const errores5xx = new Counter('errores_5xx');
const okEntregas = new Rate('entregas_ok');

export const options = {
  setupTimeout: '120s',
  discardResponseBodies: false,
  scenarios: RAMP
    ? {
        // Rampa: sube la carga hasta encontrar dónde se degrada
        rampa: {
          executor: 'ramping-vus',
          startVUs: 1,
          stages: [
            { duration: '20s', target: 10 },
            { duration: '20s', target: 25 },
            { duration: '20s', target: 50 },
            { duration: '20s', target: 100 },
            { duration: '10s', target: 0 },
          ],
        },
      }
    : {
        pico: {
          executor: 'constant-vus',
          vus: VUS,
          duration: DURATION,
        },
      },
  thresholds: {
    // El informe del TFG exige ≤2 s bajo carga normal
    't_ciclo_completo': ['p(95)<2000'],
    'entregas_ok': ['rate>0.99'],
    'errores_5xx': ['count==0'],
  },
};

export function setup() {
  // Un login por alumno sería lentísimo en setup; se reparten los tokens entre
  // los VUs. Con 40 alumnos distintos hay variedad suficiente de datos.
  const nTokens = Math.min(40, ALUMNOS);
  const tokens = [];

  for (let i = 1; i <= nTokens; i++) {
    const r = http.post(
      `${BASE}/auth/login`,
      JSON.stringify({ email: `alumno${i}@carga.test`, password: PASS }),
      { headers: { 'Content-Type': 'application/json' } }
    );
    const t = r.json('data.token') || r.json('token') || r.json('access_token');
    if (t) tokens.push(t);
  }

  if (tokens.length === 0) {
    throw new Error('No se pudo autenticar ningún alumno. ¿Sembraste con LoadTestSeeder?');
  }

  // El examen y sus preguntas: se leen una vez y se reutilizan.
  const h = { Authorization: `Bearer ${tokens[0]}`, 'Content-Type': 'application/json' };
  const exams = http.get(`${BASE}/exams`, { headers: h });
  const exam = exams.json('data.data.0');
  if (!exam) throw new Error('No hay exámenes sembrados.');

  const qs = http.get(`${BASE}/exams/${exam.id}/questions`, { headers: h });
  const preguntas = qs.json('data.data') || qs.json('data') || [];

  const respuestas = preguntas.map((q) => ({
    question_id: q.id,
    selected_option_ids: [(q.options && q.options[0] && q.options[0].id) || null],
  }));

  console.log(`setup: ${tokens.length} tokens, examen ${exam.id}, ${respuestas.length} preguntas`);

  return { tokens, examId: exam.id, respuestas };
}

export default function (data) {
  const token = data.tokens[__VU % data.tokens.length];
  const h = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };
  const B = base();

  const t0 = Date.now();

  const start = http.post(`${B}/exams/${data.examId}/attempts/start`, null, { headers: h });
  tStart.add(start.timings.duration);
  if (start.status >= 500) errores5xx.add(1);

  const attemptId = start.json('data.id');
  if (!attemptId) {
    okEntregas.add(false);
    return;
  }

  const submit = http.post(
    `${B}/exams/${data.examId}/attempts/${attemptId}/submit`,
    JSON.stringify({ answers: data.respuestas }),
    { headers: h }
  );
  tSubmit.add(submit.timings.duration);
  if (submit.status >= 500) errores5xx.add(1);

  tCiclo.add(Date.now() - t0);

  const ok = check(submit, {
    'entrega 200': (r) => r.status === 200,
  });
  okEntregas.add(ok);
}
