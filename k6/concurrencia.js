// k6 — ¿Cuántos alumnos aguanta el sistema a la vez? (rampa por escalones)
//
// Sube la carga por escalones y mide, en cada uno, la latencia de TODAS las peticiones
// (etiqueta `etapa` = alumnos conectados en ese escalón). La concurrencia soportada es
// el último escalón cuyo p95 cumple la meta (< 5 s) sin errores.
//
//   escalones: 10 → 25 → 50 → 100 → 150 → 200 alumnos, 60 s cada uno
//
// Los inicios de sesión se hacen en `setup`, en lotes de 40 por minuto: el límite de login
// por IP es 60/min y los 200 alumnos salen de una sola IP. Así el escalón mide el uso de la
// API, no el límite de peticiones. Cada alumno (VU) usa su propia cuenta alumno{n}@carga.test.
//
// Requiere la base sembrada con LoadTestSeeder (200 alumnos y un examen activo).
//
// Uso:  k6 run --summary-export=concurrencia.json k6/concurrencia.js
//       k6 run -e LEVELS=10,25,50 -e STEP=30 k6/concurrencia.js     (ensayo)

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'https://neoeducore.api.eduregistro.com/api';
const PASS = __ENV.SEED_PASSWORD || 'Carga2026';
const EMAIL = __ENV.EMAIL_PATTERN || 'alumno{n}@carga.test';
const LEVELS = (__ENV.LEVELS || '10,25,50,100,150,200').split(',').map(Number);
const STEP = Number(__ENV.STEP || 60); // segundos por escalón (15 s de subida + el resto sostenido)
const BATCH = Number(__ENV.LOGIN_BATCH || 40);
const GOAL_MS = Number(__ENV.GOAL_MS || 5000);
const MAX = Math.max(...LEVELS);

const errores5xx = new Counter('errores_5xx');
const respuestas429 = new Counter('respuestas_429');
const entregaOk = new Rate('entrega_ok');
const tLoginLote = new Trend('t_login_lote', true);

const stages = LEVELS.flatMap((target) => [
  { duration: `${Math.min(15, STEP)}s`, target },
  { duration: `${Math.max(STEP - 15, 1)}s`, target },
]);

// Un umbral por escalón: el resumen dice en cuál se rompe la meta.
const thresholds = {
  errores_5xx: ['count==0'],
  entrega_ok: ['rate>0.99'],
  // Cualquier petición fallida (401, 403, 4xx inesperados…) invalida la medición: un escalón
  // con errores no puede darse por «cumplido» solo porque sus tiempos sean buenos.
  // Los 429 de k6 también cuentan como fallo: significan que se midió el límite, no la API.
  http_req_failed: ['rate<0.01'],
};
// Si el sistema se desploma, se corta la prueba (no tiene sentido seguir apretando).
thresholds.http_req_failed.push({ threshold: 'rate<0.2', abortOnFail: true, delayAbortEval: '30s' });
for (const n of LEVELS) thresholds[`http_req_duration{etapa:${n}}`] = [`p(95)<${GOAL_MS}`];

export const options = {
  setupTimeout: '15m',
  discardResponseBodies: false,
  scenarios: {
    escalones: { executor: 'ramping-vus', startVUs: 0, stages, gracefulRampDown: '30s' },
  },
  thresholds,
};

const JSON_H = { 'Content-Type': 'application/json', Accept: 'application/json' };

export function setup() {
  const tokens = [];
  for (let desde = 1; desde <= MAX; desde += BATCH) {
    const reqs = [];
    for (let n = desde; n < desde + BATCH && n <= MAX; n++) {
      reqs.push({
        method: 'POST',
        url: `${BASE}/auth/login`,
        body: JSON.stringify({ email: EMAIL.replace('{n}', n), password: PASS }),
        params: { headers: JSON_H, tags: { fase: 'setup' } },
      });
    }
    const t0 = Date.now();
    const resps = http.batch(reqs);
    tLoginLote.add(Date.now() - t0);
    resps.forEach((r) => {
      const t = r.status === 200 ? r.json('token') || r.json('data.token') : null;
      tokens.push(t);
      if (r.status === 429) respuestas429.add(1);
    });
    console.log(`setup: ${tokens.filter(Boolean).length}/${tokens.length} sesiones`);
    // 40 inicios por minuto: por debajo del tope de 60/min por IP.
    if (desde + BATCH <= MAX) sleep(Math.max(0, 60 - (Date.now() - t0) / 1000));
  }
  // Todas las cuentas tienen que entrar: un VU sin sesión queda inactivo y el escalón «200»
  // mediría menos de 200 alumnos. Mejor abortar que dar un resultado inflado.
  const sinSesion = tokens.filter((t) => !t).length;
  if (sinSesion > 0) throw new Error(`${sinSesion} de ${MAX} cuentas no iniciaron sesión: la prueba no mediría ${MAX} alumnos.`);
  return { tokens };
}

let yaPresentoExamen = false;

function etapaActual() {
  const seg = (Date.now() - exec.scenario.startTime) / 1000;
  return LEVELS[Math.min(LEVELS.length - 1, Math.floor(seg / STEP))];
}

/** Etiqueta del escalón EN EL MOMENTO de cada petición (una vuelta puede cruzar de un escalón a otro). */
function etiqueta() {
  return { etapa: String(etapaActual()) };
}

function vigilar(res) {
  if (res.status >= 500) errores5xx.add(1);
  if (res.status === 429) respuestas429.add(1);
  return res;
}

function presentarExamen(H) {
  const lista = vigilar(http.get(`${BASE}/students/me/available-exams`, { headers: H, tags: etiqueta() }));
  const examen = lista.status === 200 ? lista.json('data.data.0') || lista.json('data.0') : null;
  if (!examen) return;
  const inicio = vigilar(http.post(`${BASE}/exams/${examen.id}/attempts/start`, null, { headers: H, tags: etiqueta() }));
  const intentoId = inicio.json('data.id');
  if (!intentoId) return;
  const qs = vigilar(http.get(`${BASE}/exams/${examen.id}/questions`, { headers: H, tags: etiqueta() }));
  const preguntas = qs.json('data.data') || qs.json('data') || [];
  const respuestas = preguntas.map((q) => ({
    question_id: q.id,
    selected_option_ids: [q.options && q.options[0] ? q.options[0].id : null],
  }));
  sleep(10 + Math.random() * 20); // el alumno contesta
  const entrega = vigilar(
    http.post(`${BASE}/exams/${examen.id}/attempts/${intentoId}/submit`, JSON.stringify({ answers: respuestas }), { headers: H, tags: etiqueta() }),
  );
  entregaOk.add(check(entrega, { 'entrega 200': (r) => r.status === 200 }));
}

export default function (data) {
  const token = data.tokens[(__VU - 1) % data.tokens.length];
  if (!token) {
    sleep(5);
    return;
  }
  const H = { ...JSON_H, Authorization: `Bearer ${token}` };

  vigilar(http.get(`${BASE}/dashboard/student-overview`, { headers: H, tags: etiqueta() }));
  sleep(2 + Math.random() * 3);

  if (!yaPresentoExamen) {
    yaPresentoExamen = true;
    presentarExamen(H);
  }

  const rutas = ['/students/me/available-exams', '/study-resources', '/calendar-events', '/notifications/unread-count', '/students/me/attempts'];
  for (let i = 0; i < 2; i++) {
    vigilar(http.get(`${BASE}${rutas[Math.floor(Math.random() * rutas.length)]}`, { headers: H, tags: etiqueta() }));
    sleep(3 + Math.random() * 5);
  }
}
