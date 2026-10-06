// k6 — Seeding de usuarios para NeoEduCore
//
// Crea 5 profesores y 60 estudiantes en la institución del ADMIN autenticado.
//
// Desde el refactor de permisos, /register es admin-only: hay que iniciar sesión
// como administrador y enviar el token en cada alta. Todos los usuarios se crean
// en la institución de ese admin (no se pueden crear instituciones aquí).
//
// Requisito: debe existir un admin y al menos un aula con una materia. El seeder
// los crea (4-A y 4-B, con asignaciones de docentes):
//   php artisan db:seed   ->  admin@neoeducore.edu.co / password123
//
// Reglas del sistema que este script respeta:
//   - Un estudiante SIEMPRE se da de alta en un aula (`group_id`): sin ella queda
//     invisible para todo docente. Por defecto, la primera aula del centro; se
//     fija otra con -e GROUP_ID=<uuid>.
//   - Un docente nuevo no ve nada hasta que el admin lo asigna a un aula y una
//     materia: tras crearlo, el script le asigna esa aula y la primera materia
//     (-e SUBJECT_ID=<uuid> para otra).
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

  // Aula y materia donde se darán de alta el alumnado y los docentes.
  const groupId = __ENV.GROUP_ID || firstId(`${BASE}/groups`, token);
  const subjectId = __ENV.SUBJECT_ID || firstId(`${BASE}/subjects`, token);
  if (!groupId || !subjectId) {
    throw new Error(
      'El centro no tiene ningún aula o ninguna materia: sin aula no se puede dar de alta a un estudiante. ' +
      'Ejecuta "php artisan db:seed" o pasa -e GROUP_ID=... -e SUBJECT_ID=...'
    );
  }
  console.log(`Aula=${groupId} Materia=${subjectId}`);

  return { runId, token, institutionId, groupId, subjectId };
}

/** Primer id de un listado paginado (`data.data[0].id`). */
function firstId(url, token) {
  const r = http.get(url, jsonHeaders(token));
  try {
    return r.json('data.data.0.id');
  } catch (e) {
    return null;
  }
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
      group_id: data.groupId,   // obligatorio: el alumnado se da de alta EN un aula
    };
  }

  const res = http.post(`${BASE}/register`, JSON.stringify(payload), jsonHeaders(data.token));
  check(res, {
    'usuario creado (201)': (r) => r.status === 201,
  });

  if (res.status !== 201) {
    console.error(`Fallo creando ${payload.email}: status=${res.status} body=${res.body}`);
    return;
  }

  // Un docente recién creado no ve nada hasta que el admin lo asigna a un aula y
  // una materia: de esa asignación sale todo lo que puede ver y hacer.
  if (isTeacher) {
    const asignacion = http.post(
      `${BASE}/teacher-assignments`,
      JSON.stringify({
        teacher_user_id: res.json('user.id'),
        group_ids: [data.groupId],
        subject_ids: [data.subjectId],
      }),
      jsonHeaders(data.token)
    );
    check(asignacion, {
      'docente asignado (200/201)': (r) => r.status === 200 || r.status === 201,
    });
    if (asignacion.status >= 300) {
      console.error(`Fallo asignando a ${payload.email}: status=${asignacion.status} body=${asignacion.body}`);
    }
  }
}

export function teardown(data) {
  console.log(`Seed completado. Institución=${data.institutionId}`);
  console.log(`Credenciales de los creados: password="${PASSWORD}" para todos.`);
  console.log(`Ejemplo profesor: profesor.1.${data.runId}@neoeducore.test`);
  console.log(`Ejemplo estudiante: estudiante.1.${data.runId}@neoeducore.test`);
}
