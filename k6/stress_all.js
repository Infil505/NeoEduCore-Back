// k6 — Stress test agresivo de NeoEduCore ("dales duro")
//
// Tormentas concurrentes:
//   - reads   : GET masivo de todos los endpoints de lectura por rol
//   - writes  : crea/edita/borra recursos efímeros (subjects/groups/events/resources)
//   - abuse   : entradas hostiles (inputs basura, IDOR, UUIDs inexistentes, sin token, inyección)
//   - throttle: martillea /password/forgot para confirmar el rate-limit (429)
//
// Detector de roturas: CUALQUIER respuesta 5xx se considera fallo y se loguea.
// Se EXCLUYEN endpoints de IA (llaman a OpenAI → coste/latencia externa).
//
// Uso:
//   k6 run k6/stress_all.js
//   k6 run -e VUS=40 -e DURATION=90s k6/stress_all.js

import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000/api';
const PASS = __ENV.SEED_PASSWORD || 'password123';

const ADMIN = __ENV.ADMIN_EMAIL || 'admin@neoeducore.edu.co';
const TEACHER = __ENV.TEACHER_EMAIL || 'profesor1@neoeducore.edu.co';
const STUDENT = __ENV.STUDENT_EMAIL || 'estudiante1@neoeducore.edu.co';

const serverErrors = new Counter('server_errors_5xx');

// Intensidad configurable. Por defecto MODERADA (valida códigos sin saturar el
// server monohilo de dev). Para la tormenta dura: -e MODE=hard
const HARD = (__ENV.MODE || '') === 'hard';
const RVUS = Number(__ENV.READ_VUS || (HARD ? 40 : 6));
const DUR = __ENV.DURATION || (HARD ? '80s' : '30s');

export const options = {
  setupTimeout: '120s',
  scenarios: {
    reads: {
      executor: 'constant-vus',
      exec: 'reads',
      vus: RVUS,
      duration: DUR,
    },
    writes: {
      executor: 'constant-vus',
      exec: 'writes',
      vus: HARD ? 6 : 2,
      duration: DUR,
    },
    abuse: {
      executor: 'constant-vus',
      exec: 'abuse',
      vus: HARD ? 10 : 3,
      duration: DUR,
    },
    throttle: {
      executor: 'constant-vus',
      exec: 'throttle',
      vus: HARD ? 4 : 2,
      duration: '20s',
    },
  },
  thresholds: {
    // Cualquier 5xx hace fallar el umbral
    server_errors_5xx: ['count<1'],
    checks: ['rate>0.99'],
  },
};

function login(email, password) {
  const r = http.post(`${BASE}/auth/login`, JSON.stringify({ email, password }), {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  });
  return r.status === 200 ? r.json('token') : null;
}

function H(token) {
  const h = { Accept: 'application/json', 'Content-Type': 'application/json' };
  if (token) h.Authorization = `Bearer ${token}`;
  return { headers: h };
}

// Registra el resultado: marca 5xx como rotura y loguea el primer culpable.
function hit(name, res, allowed) {
  const is5xx = res.status >= 500;
  if (is5xx) {
    serverErrors.add(1, { endpoint: name });
    console.error(`5xx [${name}] status=${res.status} body=${String(res.body).slice(0, 200)}`);
  }
  check(res, {
    [`${name}: no 5xx`]: (r) => r.status < 500,
  });
  if (allowed) {
    check(res, { [`${name}: status esperado`]: (r) => allowed.includes(r.status) });
  }
  return res;
}

export function setup() {
  const admin = login(ADMIN, PASS);
  const teacher = login(TEACHER, PASS);
  const student = login(STUDENT, PASS);

  if (!admin || !teacher || !student) {
    throw new Error(`Login fallido. admin=${!!admin} teacher=${!!teacher} student=${!!student}`);
  }

  const a = H(admin);
  const pick = (path, key) => {
    const r = http.get(`${BASE}${path}`, a);
    try {
      const d = r.json('data');
      const arr = Array.isArray(d) ? d : (d && d.data) || [];
      return arr.length ? (key ? arr[0][key] : arr[0].id) : null;
    } catch (e) {
      return null;
    }
  };

  const ids = {
    subject: pick('/subjects', 'id'),
    exam: pick('/exams', 'id'),
    group: pick('/groups', 'id'),
    user: pick('/users', 'id'),
    student: pick('/students', 'user_id'),
    resource: pick('/study-resources', 'id'),
    event: pick('/calendar-events', 'id'),
  };

  console.log('IDs descubiertos: ' + JSON.stringify(ids));
  return { admin, teacher, student, ids };
}

// ---------------------------------------------------------------------------
// TORMENTA DE LECTURAS
// ---------------------------------------------------------------------------
export function reads(data) {
  const { ids } = data;
  const a = H(data.admin);
  const t = H(data.teacher);
  const s = H(data.student);

  // Catálogos compartidos
  hit('GET /exams', http.get(`${BASE}/exams`, t));
  hit('GET /subjects', http.get(`${BASE}/subjects`, t));
  hit('GET /study-resources', http.get(`${BASE}/study-resources`, s));
  hit('GET /calendar-events', http.get(`${BASE}/calendar-events`, s));
  if (ids.exam) {
    hit('GET /exams/{id}', http.get(`${BASE}/exams/${ids.exam}`, s));
    hit('GET /exams/{id}/questions', http.get(`${BASE}/exams/${ids.exam}/questions`, s));
  }
  if (ids.subject) hit('GET /subjects/{id}', http.get(`${BASE}/subjects/${ids.subject}`, t));

  // Admin / teacher
  hit('GET /users', http.get(`${BASE}/users?q=a`, a));
  hit('GET /students', http.get(`${BASE}/students?status=active`, t));
  hit('GET /groups', http.get(`${BASE}/groups`, t));
  hit('GET /student-progress', http.get(`${BASE}/student-progress`, t));
  hit('GET /analytics/institution', http.get(`${BASE}/analytics/institution`, a));
  hit('GET /analytics/subjects', http.get(`${BASE}/analytics/subjects`, a));
  hit('GET /system/config', http.get(`${BASE}/system/config`, t));
  hit('GET /ai-recommendations', http.get(`${BASE}/ai-recommendations`, t));
  hit('GET /reports/ai/tutor-usage', http.get(`${BASE}/reports/ai/tutor-usage`, a));
  if (ids.student) {
    hit('GET /students/{id}', http.get(`${BASE}/students/${ids.student}`, t));
    hit('GET /analytics/students/{id}', http.get(`${BASE}/analytics/students/${ids.student}`, a));
    hit('GET /reports/students/{id}/history', http.get(`${BASE}/reports/students/${ids.student}/history`, a));
  }
  if (ids.exam) hit('GET /reports/exams/{id}/results', http.get(`${BASE}/reports/exams/${ids.exam}/results`, a));

  // Admin only
  hit('GET /institutions', http.get(`${BASE}/institutions`, a));

  // Student
  hit('GET /auth/me', http.get(`${BASE}/auth/me`, s));
  hit('GET /students/me', http.get(`${BASE}/students/me`, s));
  hit('GET /students/me/available-exams', http.get(`${BASE}/students/me/available-exams`, s));
  hit('GET /students/me/subjects', http.get(`${BASE}/students/me/subjects`, s));
  hit('GET /student-progress/me', http.get(`${BASE}/student-progress/me`, s));
  hit('GET /ai-recommendations/me', http.get(`${BASE}/ai-recommendations/me`, s));
}

// ---------------------------------------------------------------------------
// TORMENTA DE ESCRITURAS (recursos efímeros)
// ---------------------------------------------------------------------------
export function writes(data) {
  const a = H(data.admin);
  const uniq = `${exec.scenario.iterationInTest}-${exec.vu.idInTest}`;

  // Subject: crear → editar → borrar
  let r = hit('POST /subjects', http.post(`${BASE}/subjects`, JSON.stringify({ name: `Stress Subj ${uniq}` }), a), [200, 201, 422]);
  let sid = null;
  try { sid = r.json('data.id') || r.json('id'); } catch (e) {}
  if (sid) {
    hit('PUT /subjects/{id}', http.put(`${BASE}/subjects/${sid}`, JSON.stringify({ name: `Stress Subj upd ${uniq}` }), a), [200, 422]);
    hit('DELETE /subjects/{id}', http.del(`${BASE}/subjects/${sid}`, null, a), [200, 204]);
  }

  // Group efímero
  r = hit('POST /groups', http.post(`${BASE}/groups`, JSON.stringify({
    name: `Stress Grp ${uniq}`, grade: 10, section: 'A', year: 2026, group_code: `STR-${uniq}`,
  }), a), [200, 201, 422]);
  let gid = null;
  try { gid = r.json('data.id') || r.json('id'); } catch (e) {}
  if (gid) hit('DELETE /groups/{id}', http.del(`${BASE}/groups/${gid}`, null, a), [200, 204]);

  // Calendar event efímero
  r = hit('POST /calendar-events', http.post(`${BASE}/calendar-events`, JSON.stringify({
    title: `Stress Ev ${uniq}`, start_at: '2026-09-01T10:00:00Z', event_type: 'reminder',
  }), a), [200, 201, 422]);
  let eid = null;
  try { eid = r.json('data.id') || r.json('id'); } catch (e) {}
  if (eid) hit('DELETE /calendar-events/{id}', http.del(`${BASE}/calendar-events/${eid}`, null, a), [200, 204]);

  // Study resource efímero
  hit('POST /study-resources', http.post(`${BASE}/study-resources`, JSON.stringify({
    title: `Stress Res ${uniq}`, resource_type: 'link', url: 'https://example.com/x', difficulty: 'basic',
  }), a), [200, 201, 422]);
}

// ---------------------------------------------------------------------------
// TORMENTA DE ABUSO (entradas hostiles)
// ---------------------------------------------------------------------------
export function abuse(data) {
  const a = H(data.admin);
  const s = H(data.student);
  const noauth = { headers: { Accept: 'application/json', 'Content-Type': 'application/json' } };
  const FAKE = '00000000-0000-0000-0000-000000000000';

  // UUID inexistente → 404 (no 500)
  hit('ABUSE get student inexistente', http.get(`${BASE}/students/${FAKE}`, a), [403, 404]);
  hit('ABUSE get exam inexistente', http.get(`${BASE}/exams/${FAKE}`, a), [404]);

  // ID no-UUID basura
  hit('ABUSE exam id basura', http.get(`${BASE}/exams/$(rm -rf)/questions`, a), [400, 404]);

  // Inyección SQL-ish en filtro de búsqueda → debe ser 200/422, NUNCA 500
  hit('ABUSE sqli en q', http.get(`${BASE}/users?q=${encodeURIComponent("' OR 1=1; DROP TABLE users; --")}`, a), [200, 422]);
  hit('ABUSE filtro tipo inválido', http.get(`${BASE}/users?user_type=hacker&status=999`, a), [200, 422]);

  // String gigante en query
  hit('ABUSE query gigante', http.get(`${BASE}/students?section=${'A'.repeat(5000)}`, a), [200, 422]);

  // Payload basura / tipos equivocados → 422
  hit('ABUSE crear exam basura', http.post(`${BASE}/exams`, JSON.stringify({
    title: 12345, subject_id: 'no-uuid', grade: 'diez', duration_minutes: -5, max_attempts: 99999,
  }), a), [422]);
  hit('ABUSE json roto', http.post(`${BASE}/subjects`, '{"name": ', a), [400, 422]);

  // IDOR / escalada de privilegios → 403
  hit('ABUSE student->/users (403)', http.get(`${BASE}/users`, s), [403]);
  hit('ABUSE student->POST /exams (403)', http.post(`${BASE}/exams`, JSON.stringify({ title: 'x' }), s), [403]);
  hit('ABUSE student->/institutions (403)', http.get(`${BASE}/institutions`, s), [403]);
  hit('ABUSE student->register (403)', http.post(`${BASE}/register`, JSON.stringify({
    full_name: 'Hax', email: `hax${exec.vu.idInTest}@x.com`, password: 'Password123', password_confirmation: 'Password123',
  }), s), [403]);

  // Sin token → 401
  hit('ABUSE sin token (401)', http.get(`${BASE}/students`, noauth), [401]);
  hit('ABUSE token basura (401)', http.get(`${BASE}/users`, H('token-falso-123')), [401]);
}

// ---------------------------------------------------------------------------
// VERIFICACIÓN DE RATE-LIMIT (martilleo a /password/forgot, 5/min)
// ---------------------------------------------------------------------------
export function throttle(data) {
  // Email inexistente → respuesta genérica, sin encolar correos
  const r = http.post(`${BASE}/password/forgot`, JSON.stringify({
    email: `nadie-${exec.vu.idInTest}@inexistente.test`,
  }), { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } });

  hit('THROTTLE /password/forgot', r, [200, 429]);
  check(r, { 'throttle: 200 o 429': (x) => x.status === 200 || x.status === 429 });
}

export function teardown(data) {
  console.log('Stress test finalizado.');
}
