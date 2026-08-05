// k6 — Descomposición del tiempo de respuesta
//
// Separa el coste FIJO de arranque del framework (que Octane elimina en
// producción al mantener la app en memoria) del trabajo REAL de cada endpoint.
//
//   coste_endpoint = t_endpoint - t_ping
//
// `/api/ping` es una ruta pública mínima: mide bootstrap + routing y nada más.
// Se ejecuta con 1 VU para medir sin contención.
//
//   k6 run k6/baseline_decompose.js

import http from 'k6/http';
import { Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000/api';
const PASS = __ENV.SEED_PASSWORD || 'Carga2026';

const tPing = new Trend('a_ping_bootstrap', true);
const tSubjects = new Trend('b_get_subjects', true);
const tExams = new Trend('c_get_exams', true);
const tStart = new Trend('d_attempt_start', true);
const tSubmit = new Trend('e_attempt_submit', true);

export const options = {
  scenarios: { base: { executor: 'constant-vus', vus: 1, duration: __ENV.DURATION || '25s' } },
};

export function setup() {
  const r = http.post(
    `${BASE}/auth/login`,
    JSON.stringify({ email: 'alumno1@carga.test', password: PASS }),
    { headers: { 'Content-Type': 'application/json' } }
  );
  const token = r.json('token') || r.json('data.token');
  if (!token) throw new Error('login falló');

  const h = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };
  const exam = http.get(`${BASE}/exams`, { headers: h }).json('data.data.0');
  const preguntas = http.get(`${BASE}/exams/${exam.id}/questions`, { headers: h }).json('data');

  const respuestas = preguntas.map((q) => ({
    question_id: q.id,
    selected_option_ids: [q.options[0].id],
  }));

  return { token, examId: exam.id, respuestas };
}

export default function (data) {
  const h = { Authorization: `Bearer ${data.token}`, 'Content-Type': 'application/json' };

  tPing.add(http.get(`${BASE}/ping`).timings.duration);
  tSubjects.add(http.get(`${BASE}/subjects`, { headers: h }).timings.duration);
  tExams.add(http.get(`${BASE}/exams`, { headers: h }).timings.duration);

  const start = http.post(`${BASE}/exams/${data.examId}/attempts/start`, null, { headers: h });
  tStart.add(start.timings.duration);

  const attemptId = start.json('data.id');
  if (attemptId) {
    const submit = http.post(
      `${BASE}/exams/${data.examId}/attempts/${attemptId}/submit`,
      JSON.stringify({ answers: data.respuestas }),
      { headers: h }
    );
    tSubmit.add(submit.timings.duration);
  }
}
