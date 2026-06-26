// k6 — Seeding de usuarios para NeoEduCore
//
// Crea 5 profesores y 60 estudiantes en la institución del ADMIN autenticado.
//
// Desde el refactor de permisos, /register es admin-only: hay que iniciar sesión
// como administrador y enviar el token en cada alta. Todos los usuarios se crean
// en la institución de ese admin (no se pueden crear instituciones aquí).
//
// Requisito: debe existir un admin. El seeder lo crea:
//   php artisan db:seed   ->  admin@neoeducore.edu.co / password123
//
// Uso:
//   k6 run k6/seed_users.js
//   k6 run -e ADMIN_EMAIL=admin@x.com -e ADMIN_PASSWORD=Secret123 k6/seed_users.js
//   k6 run -e BASE_URL=http://127.0.0.1:8000/api k6/seed_users.js

import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000/api';

const ADMIN_EMAIL = __ENV.ADMIN_EMAIL || 'admin@neoeducore.edu.co';
const ADMIN_PASSWORD = __ENV.ADMIN_PASSWORD || 'password123';

const PASSWORD = __ENV.SEED_PASSWORD || 'Password123';

const N_TEACHERS = Number(__ENV.N_TEACHERS || 5);
const N_STUDENTS = Number(__ENV.N_STUDENTS || 60);
const TOTAL = N_TEACHERS + N_STUDENTS;

export const options = {
  scenarios: {
    seed: {
      executor: 'shared-iterations',
      vus: 5,
      iterations: TOTAL,
      maxDuration: '10m',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
  },
};

function jsonHeaders(token) {
  const h = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (token) h.Authorization = `Bearer ${token}`;
  return { headers: h };
}

export function setup() {
  const runId = `${Date.now()}`;

  // Login como admin para obtener el token
  const res = http.post(
    `${BASE}/auth/login`,
    JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASSWORD }),
    jsonHeaders()
  );

  const ok = check(res, {
    'setup: login admin (200)': (r) => r.status === 200 && !!r.json('token'),
  });

  if (!ok) {
    throw new Error(
      `No se pudo iniciar sesión como admin (${ADMIN_EMAIL}). ` +
      `¿Ejecutaste "php artisan db:seed"? status=${res.status} body=${res.body}`
    );
  }

  const token = res.json('token');
  const institutionId = res.json('user.institution_id');
  console.log(`Admin autenticado. Institución=${institutionId} (runId=${runId})`);

  return { runId, token, institutionId };
}

export default function (data) {
  const i = exec.scenario.iterationInTest; // 0..TOTAL-1, único global
  const isTeacher = i < N_TEACHERS;

  let payload;
  if (isTeacher) {
    const n = i + 1;
    payload = {
      full_name: `Profesor ${n}`,
      email: `profesor.${n}.${data.runId}@neoeducore.test`,
      password: PASSWORD,
      password_confirmation: PASSWORD,
      user_type: 'teacher',
    };
  } else {
    const n = i - N_TEACHERS + 1; // estudiante 1..N_STUDENTS
    payload = {
      full_name: `Estudiante ${n}`,
      email: `estudiante.${n}.${data.runId}@neoeducore.test`,
      password: PASSWORD,
      password_confirmation: PASSWORD,
      user_type: 'student',
    };
  }

  const res = http.post(`${BASE}/register`, JSON.stringify(payload), jsonHeaders(data.token));
  check(res, {
    'usuario creado (201)': (r) => r.status === 201,
  });

  if (res.status !== 201) {
    console.error(`Fallo creando ${payload.email}: status=${res.status} body=${res.body}`);
  }
}

export function teardown(data) {
  console.log(`Seed completado. Institución=${data.institutionId}`);
  console.log(`Credenciales de los creados: password="${PASSWORD}" para todos.`);
  console.log(`Ejemplo profesor: profesor.1.${data.runId}@neoeducore.test`);
  console.log(`Ejemplo estudiante: estudiante.1.${data.runId}@neoeducore.test`);
}
