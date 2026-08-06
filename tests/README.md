# API Tests Guide

Este proyecto contiene tests feature completos para todos los endpoints de la API REST.

## Estructura de Tests

```
tests/
├── Feature/
│   ├── Auth/
│   │   ├── AuthSessionTest.php           (GET /auth/me, POST /auth/logout)
│   │   ├── LoginRegisterTest.php         (Register, Login)
│   │   └── PasswordResetTest.php         (Password reset & change)
│   ├── Crud/
│   │   ├── StudentsCrudTest.php          (All student endpoints)
│   │   ├── GroupsCrudTest.php            (All group endpoints)
│   │   ├── SubjectsTest.php              (All subject endpoints)
│   │   ├── ExamsCrudTest.php             (All exam endpoints)
│   │   ├── QuestionsCrudTest.php         (All question endpoints)
│   │   ├── ExamAttemptsTest.php          (Exam attempt endpoints)
│   │   ├── StudentAnswersTest.php        (Student answer endpoints)
│   │   ├── StudyResourcesTest.php        (Study resource endpoints)
│   │   ├── CalendarEventsTest.php        (Calendar event endpoints)
│   │   ├── StudentProgressTest.php       (Student progress endpoints)
│   │   ├── UsersTest.php                 (User management endpoints)
│   │   ├── InstitutionsTest.php          (Institution endpoints)
│   │   ├── AiRecommendationsTest.php     (AI recommendation endpoints)
│   │   └── ReportsTest.php               (Report endpoints)
│   ├── Academic/
│   │   ├── BulkReassignmentTest.php      (Reasignación masiva: grupo y materias)
│   │   ├── ResetProgressTest.php         (Reseteo de progreso para repitentes)
│   │   └── GroupStudentsTest.php         (Alta/baja de estudiantes en un grupo)
│   ├── Exams/
│   │   └── AnswerLeakTest.php            (El alumno no ve la respuesta antes de entregar)
│   ├── Db/
│   │   ├── SchemaLoadedTest.php          (El schema de tests carga)
│   │   ├── SchemaIntegrityTest.php       (Invariantes: tipos uuid, unique de materia)
│   │   └── CascadeIntegrityTest.php      (Cascadas de borrado y FK del modelo TFG)
│   ├── Integration/
│   │   ├── Level1_ExamFullFlowTest.php   (Flujo completo: start→submit→grade→AI)
│   │   ├── Level2_RbacIdorTest.php       (RBAC, IDOR, cross-tenant)
│   │   ├── Level3_StudentLifecycleTest.php (Grupos, materias, exámenes disponibles)
│   │   ├── Level4_AiTutorFlowTest.php    (Tutor IA: chat, sesiones, modos, diagnóstico)
│   │   ├── Level5_AnalyticsReportsTest.php (Analíticas y reportes)
│   │   ├── Level6_SystemConfigTest.php   (Configuración del sistema)
│   │   └── Level7_AcademicCycleTest.php  (Ciclo de fin de año end-to-end)
│   ├── AI/
│   │   └── AiTutorEfficiencyTest.php     (Caché de contexto, JSONB incremental, límite global)
│   ├── Perf/
│   │   └── QueryBudgetTest.php           (Presupuesto de queries / guardia anti-N+1)
│   └── Routes/
│       ├── ProtectedRoutesRequireAuthTest.php
│       └── PublicRoutesTest.php
├── Traits/
│   ├── ApiAuth.php                       (signInTeacher, signInAdmin, signInStudent)
│   └── UsesPostgresSchema.php            (Recrea schema PostgreSQL desde 01_schema.sql)
└── TestCase.php                          (Base test class)
```

**Total: 265 tests, 944 assertions**

## Ejecución

### Todos los tests
```bash
php artisan test
```

### Tests específicos
```bash
# Auth tests
php artisan test tests/Feature/Auth/

# CRUD tests
php artisan test tests/Feature/Crud/

# Test específico
php artisan test tests/Feature/Crud/StudentsCrudTest.php
```

### Tests con coverage
```bash
php artisan test --coverage
```

### Tests en modo verbose
```bash
php artisan test --verbose
```

## Endpoints Cubiertos

### Autenticación (13 tests)
- ✅ POST /register
- ✅ POST /auth/login
- ✅ GET /auth/me
- ✅ POST /auth/logout
- ✅ POST /password/forgot
- ✅ POST /password/verify
- ✅ POST /password/reset
- ✅ POST /password/change

### Health Check (1 test)
- ✅ GET /ping

### Estudiantes (6 tests)
- ✅ GET /students
- ✅ GET /students/{id}
- ✅ GET /students/me
- ✅ PUT /students/{id}
- ✅ PATCH /students/{id}/status

### Grupos (5 tests)
- ✅ GET /groups
- ✅ POST /groups
- ✅ GET /groups/{id}
- ✅ PUT /groups/{id}
- ✅ DELETE /groups/{id}

### Membresía de grupo — `GroupStudentsTest` (7 tests)
- ✅ POST /groups/{group}/students — alta por lista, setea `institution_id`, recuenta `student_count`
- ✅ Alta idempotente (repetir no duplica)
- ✅ DELETE /groups/{group}/students — baja **lógica** (`left_at`), conserva historial, recuenta
- ✅ Re-alta de un estudiante dado de baja reabre la membresía
- ✅ Estudiantes de otra institución se ignoran
- ✅ `student` no puede gestionar membresías (403)
- ✅ `student_user_ids` requerido (422)

### Asignaturas — `SubjectsTest` (15 tests)
- ✅ GET /subjects (+ filtro `search`)
- ✅ GET /subjects/{id}
- ✅ POST/PUT/DELETE /subjects — **solo admin**
- ✅ `teacher` y `student` no pueden crear, renombrar ni eliminar (403)
- ✅ Nombre único por institución: duplicado exacto, por mayúsculas y por espacios (422)
- ✅ "Matemática 1er grado" y "Matemática 2do grado" coexisten
- ✅ Mismo nombre permitido en otra institución
- ✅ Renombrar sobre un nombre existente falla; renombrar a su propio nombre no

### Reasignación masiva — `BulkReassignmentTest` (18 tests)
- ✅ POST /bulk/reassign-group — por lista y por `from_group_id`; cierra membresía anterior
- ✅ Recuenta `student_count` de origen **y** destino
- ✅ Sincroniza `students.grade/section/group_code` (y se puede desactivar)
- ✅ Los ya activos en el destino no cuentan como movidos
- ✅ Ids desconocidos vuelven en `skipped` sin abortar el lote
- ✅ Grupo de otra institución → 404; lista y `from_group_id` mutuamente excluyentes → 422
- ✅ POST /bulk/reassign-subjects — modos `replace` / `add` / `remove`
- ✅ `add` idempotente; materia de otra institución y modo inválido → 422
- ✅ `teacher` no puede hacer reasignaciones masivas (403)

### Reseteo de progreso (repitentes) — `ResetProgressTest` (11 tests)
- ✅ POST /bulk/reset-progress — por lista y por `from_group_id`
- ✅ Sin `subject_ids` resetea todas las materias; con `subject_ids` solo las indicadas
- ✅ **El reseteo sobrevive a un `recalcFromAttempts`** (marca `reset_at`) — el test que de verdad importa
- ✅ Un intento **posterior** al corte sí vuelve a computar (50, no el promedio con el viejo)
- ✅ El historial de intentos NO se borra
- ✅ `overall_average` se recomputa
- ✅ `teacher` no puede resetear (403); materia de otra institución y lista+grupo juntos → 422
- ✅ Ids desconocidos vuelven en `skipped`

### Integridad del esquema — `SchemaIntegrityTest` (2 tests)
- ✅ `personal_access_tokens.tokenable_id`, `users.id` e `institutions.id` siguen siendo `uuid`
- ✅ Existe el índice único funcional de nombre de materia (`lower` + `btrim`)

### Exámenes (5 tests)
- ✅ GET /exams
- ✅ POST /exams
- ✅ GET /exams/{id}
- ✅ PUT /exams/{id}
- ✅ DELETE /exams/{id}

### Preguntas (4 tests)
- ✅ GET /exams/{exam}/questions
- ✅ POST /exams/{exam}/questions
- ✅ PUT /questions/{id}
- ✅ DELETE /questions/{id}

### Intentos de Examen (3 tests)
- ✅ POST /exams/{exam}/attempts/start
- ✅ POST /exams/{exam}/attempts/{attempt}/submit
- ✅ GET /exams/{exam}/attempts/{attempt}

### Respuestas de Estudiantes (2 tests)
- ✅ GET /exam-attempts/{attempt}/answers
- ✅ PATCH /student-answers/{id}/review

### Progreso de Estudiante (4 tests)
- ✅ GET /student-progress
- ✅ GET /student-progress/me
- ✅ POST /student-progress
- ✅ POST /student-progress/recalc

### Recursos de Estudio (5 tests)
- ✅ GET /study-resources
- ✅ POST /study-resources
- ✅ GET /study-resources/{id}
- ✅ PUT /study-resources/{id}
- ✅ DELETE /study-resources/{id}

### Eventos de Calendario (5 tests)
- ✅ GET /calendar-events
- ✅ POST /calendar-events
- ✅ GET /calendar-events/{id}
- ✅ PUT /calendar-events/{id}
- ✅ DELETE /calendar-events/{id}

### Recomendaciones de IA (4 tests)
- ✅ GET /ai-recommendations
- ✅ GET /ai-recommendations/me
- ✅ GET /ai-recommendations/{id}
- ✅ POST /exam-attempts/{attempt}/recommendations/regenerate

### Usuarios (6 tests)
- ✅ GET /users
- ✅ GET /users/{id}
- ✅ PUT /users/{id}
- ✅ PATCH /users/{id}/status
- ✅ PATCH /users/{id}/reset-password
- ✅ DELETE /users/{id}

### Instituciones (4 tests)
- ✅ GET /institutions
- ✅ GET /institutions/{id}
- ✅ PUT /institutions/{id}
- ✅ PATCH /institutions/{id}/toggle

### Reportes (9 tests)
- ✅ GET /reports/exams/{exam}/results
- ✅ GET /reports/exams/{exam}/results.csv
- ✅ GET /reports/exams/{exam}/summary — series de los gráficos, recuentos exactos
- ✅ GET /reports/exams/{exam}/summary — respeta `passing_percentage` de la institución
- ✅ GET /reports/exams/{exam}/summary — 403 sobre el examen de otro docente
- ✅ GET /reports/students/{student}/history
- ✅ GET /reports/students/{student}/history.csv
- ✅ GET /reports/students/{student}/summary — tendencia en orden cronológico
- ✅ GET /reports/students/{student}/summary — parámetro `points` y su validación

### Estrategias del tutor (7 tests)
- ✅ `SECTIONS` cubre todo el enum `AiRecommendationType`
- ✅ GET /reports/students/me/strategies — agrupadas y en orden narrativo
- ✅ **El historial de chat nunca aparece en el reporte** — frontera de [175]
- ✅ GET /reports/students/{student}/strategies — docente acotado a sus exámenes
- ✅ GET /reports/students/{student}/strategies — admin ve toda la institución
- ✅ 403 si un estudiante pide las estrategias de otro
- ✅ `limit` acota cada sección y se valida

### Visibilidad de exámenes por rol (8 tests)
Regresión de las brechas de seguridad del 05/08/2026. **Verificados fallando sin
el arreglo** (neutralizando `Exam::scopeVisibleTo`).
- ✅ El estudiante no ve borradores en `GET /exams`
- ✅ 404 al leer un examen no asignado a sus grupos (`show` y `questions`)
- ✅ 404 al leer un borrador aunque esté asignado a su grupo
- ✅ Sí lee el examen activo asignado a su grupo
- ✅ 404 fuera de la ventana de disponibilidad
- ✅ No ve el correo del docente (sí el nombre)
- ✅ No ve `max_attempts`, `randomize_questions`, `allow_review_after_submission`, `show_results_immediately`
- ✅ El docente sigue viendo borradores y el registro completo

### Tests de Integración (60 tests)
- ✅ Level 1 — Flujo completo examen (11 tests): start, submit, auto-grade, pausa/resume, expiración, adecuación curricular, IA
- ✅ Level 2 — RBAC e IDOR (12 tests): acceso cruzado de intentos/exámenes, roles, cross-tenant
- ✅ Level 3 — Ciclo de vida del estudiante (8 tests): grupos, materias, exámenes disponibles
- ✅ Level 4 — Tutor IA (12 tests): chat, sesiones, modos ask/explain/practice, diagnóstico
- ✅ Level 5 — Analíticas y reportes (9 tests): institution/subjects/student analytics, CSV, historial, tutor usage
- ✅ Level 6 — Configuración del sistema (8 tests): lectura/escritura config, validaciones, roles

**Total: 265 tests, 944 assertions**

## Helpers de Autenticación

En `tests/Traits/ApiAuth.php` hay helpers útiles:

```php
// Sign in como profesor
$teacher = $this->signInTeacher(['institution_id' => $institution->id]);

// Sign in como admin
$admin = $this->signInAdmin(['institution_id' => $institution->id]);

// Actuar como usuario específico
$this->actingAs($user, 'sanctum');
```

## Notas Importantes

1. **Base de datos de test**: Los tests usan PostgreSQL. Asegúrate de tener configurado el `.env.testing`.

2. **Factories**: Se utilizan factories de Laravel para crear datos de prueba. Asegúrate de que todas las factories estén correctamente configuradas.

3. **Autenticación**: Los tests usan Laravel Sanctum para autenticación. Los endpoints protegidos ya incluyen la autenticación automáticamente.

4. **Tenant Scoping**: Algunos modelos usan el trait `TenantScoped`. Los tests manejan esto automáticamente mediante el contenedor de la aplicación.

5. **Base de datos**: Los tests limpian la base de datos automáticamente entre ejecuciones.

## Agregar Nuevos Tests

Para agregar tests de nuevos endpoints:

1. Crear un archivo en `tests/Feature/Crud/`
2. Extender `TestCase` e importar `ApiAuth` trait
3. Usar los helpers de autenticación
4. Seguir el patrón nombre_endpoint_operacion:

```php
public function test_create_resource(): void
{
    $this->signInTeacher();
    
    $res = $this->postJson('/api/endpoint', [...]);
    
    $res->assertCreated();
    $this->assertDatabaseHas('table', [...]);
}
```
