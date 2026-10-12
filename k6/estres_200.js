// k6 — Prueba de estrés: 200 alumnos conectados a la vez, contra producción (Coolify)
//
// Cada VU es UN alumno distinto (alumno{n}@carga.test): inicia sesión, ve su panel,
// y en su primera vuelta presenta el examen sembrado (start + submit). Después sigue
// navegando con pausas de lectura, como una persona. Así se mide lo que importa: el
// pico de inicios de sesión, el de entregas y el uso sostenido de 200 sesiones.
//
// Antes de correrla:
//   1. La base tiene que estar sembrada con LoadTestSeeder (200 alumnos + un examen
//      activo): ESTUDIANTES=200 php artisan db:seed --class=LoadTestSeeder
//      ⚠️ Ese seeder se niega a correr con APP_ENV=production y escribe en la base:
//      solo contra la base de pruebas.
//   2. Subir TEMPORALMENTE en la App el límite de inicios de sesión por IP: los 200
//      alumnos salen de UNA sola IP (la de quien lanza k6) y el valor por defecto
//      (60/min) devolvería 429 a casi todos. Poner RATE_LIMIT_LOGIN_IP_PER_MINUTE=1000
//      y volver al valor normal al terminar.
//   3. Los 429 que aun así ocurran se cuentan aparte (`respuestas_429`), no como fallo
//      del servidor.
//
// Uso (NO está pensada para correr desde el droplet: que la red la ponga quien prueba):
//   k6 run k6/estres_200.js
//   k6 run -e VUS=50 -e HOLD=60s k6/estres_200.js            (ensayo corto antes del completo)
//   k6 run -e BASE_URL=https://otra.api/api k6/estres_200.js
//   k6 run --summary-export=estres_200.json k6/estres_200.js
//
// Qué se considera bueno (meta del usuario: latencia < 5 s):
//   p95 de cualquier petición < 5 s · cero 5xx · inicios de sesión y entregas > 99 % correctos.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'https://neoeducore.api.eduregistro.com/api';
const PASS = __ENV.SEED_PASSWORD || 'Carga2026';
// `{n}` se sustituye por el número de VU (1..VUS).
const EMAIL = __ENV.EMAIL_PATTERN || 'alumno{n}@carga.test';

const VUS = Number(__ENV.VUS || 200);
const RAMP = __ENV.RAMP || '30s'; // los 200 entran en 30 s: el pico de inicios de sesión
const HOLD = __ENV.HOLD || '3m'; // y se mantienen conectados
const DOWN = __ENV.DOWN || '15s';

const tLogin = new Trend('t_login', true);
const tPanel = new Trend('t_panel', true);
const tLecturas = new Trend('t_lecturas', true);
const tInicio = new Trend('t_iniciar_examen', true);
const tEntrega = new Trend('t_entregar_examen', true);
const errores5xx = new Counter('errores_5xx');
const respuestas429 = new Counter('respuestas_429');
const loginOk = new Rate('login_ok');
const entregaOk = new Rate('entrega_ok');

export const options = {
  discardResponseBodies: false,
  scenarios: {
    alumnos: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: RAMP, target: VUS },
        { duration: HOLD, target: VUS },
        { duration: DOWN, target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: {
    errores_5xx: ['count==0'],
    http_req_duration: ['p(95)<5000'], // meta: < 5 s
    t_login: ['p(95)<5000'],
    t_panel: ['p(95)<5000'],
    t_lecturas: ['p(95)<5000'],
    t_iniciar_examen: ['p(95)<5000'],
    t_entregar_examen: ['p(95)<5000'],
    login_ok: ['rate>0.99'],
    entrega_ok: ['rate>0.99'],
  },
};

// Estado propio de cada VU (k6 lo conserva entre vueltas del mismo VU).
let token = null;
let yaPresentoExamen = false;

const JSON_H = { 'Content-Type': 'application/json', Accept: 'application/json' };

function auth() {
  return { headers: { ...JSON_H, Authorization: `Bearer ${token}` } };
}

/** Cuenta 5xx y 429 sin mezclarlos con los fallos de lógica. */
function vigilar(res) {
  if (res.status >= 500) errores5xx.add(1);
  if (res.status === 429) respuestas429.add(1);
  return res;
}

function iniciarSesion() {
  const res = vigilar(
    http.post(`${BASE}/auth/login`, JSON.stringify({ email: EMAIL.replace('{n}', __VU), password: PASS }), {
      headers: JSON_H,
    }),
  );
  tLogin.add(res.timings.duration);
  const t = res.status === 200 ? res.json('token') || res.json('data.token') || res.json('access_token') : null;
  loginOk.add(Boolean(t));
  if (t) token = t;
  return Boolean(t);
}

/** Lo que carga el alumno al entrar: lo mismo que el frontend, en el mismo orden. */
function panel() {
  const r = vigilar(http.get(`${BASE}/dashboard/student-overview`, auth()));
  tPanel.add(r.timings.duration);
  check(r, { 'panel 200': (x) => x.status === 200 });
  return r;
}

/** Pantallas que el alumno recorre mientras está conectado. */
function navegar() {
  const rutas = [
    '/students/me/available-exams',
    '/study-resources',
    '/calendar-events',
    '/notifications/unread-count',
    '/students/me/attempts',
  ];
  const ruta = rutas[Math.floor(Math.random() * rutas.length)];
  const r = vigilar(http.get(`${BASE}${ruta}`, auth()));
  tLecturas.add(r.timings.duration);
  check(r, { 'lectura sin 5xx': (x) => x.status < 500 });
}

/** Presenta el primer examen disponible: start + submit con la primera opción de cada pregunta. */
function presentarExamen() {
  const lista = vigilar(http.get(`${BASE}/students/me/available-exams`, auth()));
  const examen = lista.status === 200 ? lista.json('data.data.0') || lista.json('data.0') : null;
  if (!examen) return;

  const inicio = vigilar(http.post(`${BASE}/exams/${examen.id}/attempts/start`, null, auth()));
  tInicio.add(inicio.timings.duration);
  const intentoId = inicio.json('data.id');
  if (!intentoId) {
    // Sin intento (ya agotó los suyos, examen cerrado…): no cuenta como fallo de carga.
    return;
  }

  const qs = vigilar(http.get(`${BASE}/exams/${examen.id}/questions`, auth()));
  const preguntas = qs.json('data.data') || qs.json('data') || [];
  const respuestas = preguntas.map((q) => ({
    question_id: q.id,
    selected_option_ids: [q.options && q.options[0] ? q.options[0].id : null],
  }));

  // El alumno tarda en contestar: entre 20 y 60 s, no todos a la vez.
  sleep(20 + Math.random() * 40);

  const entrega = vigilar(
    http.post(`${BASE}/exams/${examen.id}/attempts/${intentoId}/submit`, JSON.stringify({ answers: respuestas }), auth()),
  );
  tEntrega.add(entrega.timings.duration);
  entregaOk.add(check(entrega, { 'entrega 200': (r) => r.status === 200 }));
}

export default function () {
  if (!token) {
    // Escalonado dentro de la rampa: cada alumno entra en su propio momento.
    sleep(Math.random() * 3);
    if (!iniciarSesion()) {
      sleep(5); // sesión fallida: reintenta en la vuelta siguiente, sin martillear
      return;
    }
  }

  panel();
  sleep(2 + Math.random() * 4);

  if (!yaPresentoExamen) {
    yaPresentoExamen = true;
    presentarExamen();
  }

  // El resto del tiempo: navegación con pausas de lectura (3–10 s).
  for (let i = 0; i < 4; i++) {
    navegar();
    sleep(3 + Math.random() * 7);
  }
}
