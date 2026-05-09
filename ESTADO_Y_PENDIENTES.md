# NeoEduCore — Estado del proyecto y pendientes
**Última actualización:** 9 de mayo de 2026  
**Rama activa:** Darwin  
**Tests:** 142 pasando / 0 fallando

---

## Índice
1. [Arquitectura general](#1-arquitectura-general)
2. [Estado actual por módulo](#2-estado-actual-por-módulo)
3. [Brechas encontradas vs documento TFG](#3-brechas-encontradas-vs-documento-tfg)
4. [Bugs activos](#4-bugs-activos)
5. [TODO priorizado](#5-todo-priorizado)
6. [Referencia de endpoints existentes](#6-referencia-de-endpoints-existentes)

---

## 1. Arquitectura general

El proyecto es una **API REST en Laravel 12 + PostgreSQL** con las siguientes capas:

```
HTTP Request
    ↓
Router (routes/api.php)
    ↓
Middleware: auth:sanctum → SetTenantFromAuth → RequireRole
    ↓
Controller (app/Http/Controllers/)
    ↓
Service / Domain (app/Services/ + app/Domain/)
    ↓
Eloquent ORM (app/Models/)
    ↓
PostgreSQL (schema en database/sql/01_schema.sql)
```

**Servicios externos:**
- OpenAI GPT-4o-mini — recomendaciones IA (regenerate) y tutor conversacional
- SMTP — correo de recuperación de contraseña

**Multi-tenancy:** cada request autenticado inyecta `institution_id` via `SetTenantFromAuth`, activando el scope `TenantScoped` en todos los modelos. Si el scope detecta contexto HTTP sin `institution_id`, lanza `RuntimeException` (detección temprana de bugs de configuración).

**RBAC:** middleware `RequireRole` valida roles por ruta. Roles: `admin`, `teacher`, `student`, `parent`.

---

## 2. Estado actual por módulo

### ✅ Autenticación
- **Register** `POST /api/register` — crea user + perfil student automáticamente si es estudiante
- **Login** `POST /api/auth/login` — Sanctum token, verifica contra `password_hash`
- **Logout** `POST /api/auth/logout`
- **Me** `GET /api/auth/me`
- **Forgot password** `POST /api/password/forgot` — envía correo con link
- **Verify token** `POST /api/password/verify`
- **Reset password** `POST /api/password/reset`
- **Change password** `POST /api/password/change` (autenticado)

**Notas:** PasswordPolicy del dominio aplicada correctamente en reset y change.

---

### ✅ Gestión de usuarios (admin)
- CRUD completo: list, show, update, set-status, reset-password, **delete**
- Ruta: `GET/PUT/PATCH/DELETE /api/users/{id}`
- `DELETE /api/users/{id}` — revoca tokens y elimina el usuario; cascada a perfil `Student`, intentos, respuestas, progreso y recomendaciones. Los exámenes que creó el profesor quedan con `created_by_teacher_id = NULL`.

---

### ✅ Instituciones
- CRUD completo
- Ruta: `/api/institutions`

---

### ✅ Materias (Subjects)
- CRUD completo
- Ruta: `/api/subjects`
- `DELETE /api/subjects/{id}` — cascada DB: exámenes → preguntas → opciones → intentos → respuestas. También elimina inscripciones (`student_subjects`) y progreso por materia.

---

### ✅ Grupos
- CRUD completo + asignación/baja de estudiantes (upsert batch)
- Ruta: `/api/groups`
- `DELETE /api/groups/{id}` — cascada DB: membresías (`group_students`) y asignaciones de examen (`exam_targets`). Los estudiantes y exámenes NO se eliminan.

---

### ✅ Estudiantes
- List, show, update, me, set-status, bulk-upload (CSV/XLSX hasta 5000 filas)
- Campo `learning_style` (ENUM: `visual`/`auditivo`/`lector`) para adaptar tutor IA
- PK = `user_id` (no `id`)
- Ruta: `/api/students`

---

### ✅ Exámenes — Creación y gestión
- CRUD completo con máquina de estados: `draft → published → active → completed`
- Asignación a grupos via `exam_targets`
- Campos: `randomize_questions`, `duration_minutes`, `max_attempts`, `available_from/until`
- Ruta: `/api/exams`

---

### ✅ Preguntas
- CRUD con validación por tipo:
  - `multiple_choice` → exactamente 4 opciones, 1 correcta
  - `true_false` → exactamente 2 opciones, 1 correcta
  - `short_answer` → requiere `correct_answer_text`, sin opciones
  - `essay` → validado, calificado como `needs_review` (revisión manual)
- Ruta: `GET /api/exams/{exam}/questions`, `POST/PUT/DELETE /api/questions/{id}`

---

### ✅ Intento de examen (flujo principal)
- **Start** `POST /api/exams/{exam}/attempts/start`
  - Valida: status=active, ventana de disponibilidad, max_attempts
  - Race condition protegida con `lockForUpdate()` + `DB::transaction()`
- **Submit** `POST /api/exams/{exam}/attempts/{attempt}/submit`
  - Valida deadline descontando tiempo de pausa (`total_paused_seconds`)
  - Auto-califica MC y TF; deja SA/Essay como `needs_review`
  - Calcula `score` y `max_score`; actualiza progreso por materia
  - Genera recomendaciones IA (plantillas estáticas por defecto)
- **Pause** `PATCH /api/exams/{exam}/attempts/{attempt}/pause`
  - Registra `paused_at`; bloquea submit mientras está pausado
- **Resume** `PATCH /api/exams/{exam}/attempts/{attempt}/resume`
  - Acumula `total_paused_seconds`; el deadline se extiende automáticamente
- **Show** `GET /api/exams/{exam}/attempts/{attempt}`
- **List** `GET /api/exams/{exam}/attempts` (admin/teacher)

---

### ✅ Respuestas de estudiantes
- **List** `GET /api/exam-attempts/{attempt}/answers`
- **Review** `PATCH /api/student-answers/{answer}/review` — revisión manual de SA/Essay
- `review_status` es ENUM PostgreSQL nativo: `auto_graded` | `needs_review` | `reviewed`

---

### ✅ Recomendaciones IA
- **List** `GET /api/ai-recommendations` (admin/teacher)
- **Me** `GET /api/ai-recommendations/me` (student)
- **Show** `GET /api/ai-recommendations/{id}`
- **Regenerate post-examen** `POST /api/exam-attempts/{attempt}/recommendations/regenerate`
  - Llama GPT-4o-mini; límite: 1 generación + 3 regeneraciones por intento
  - Fallback a plantillas estáticas si OpenAI falla

---

### ✅ Tutor IA conversacional
- **Chat** `POST /api/ai/tutor/chat` — chat con contexto del estudiante (perfil + progreso + historial)
  - Adapta el prompt según `learning_style` del estudiante
  - Limita historial a 60 mensajes almacenados, 20 mensajes de contexto a OpenAI
  - Fallback con mensaje de error amigable si OpenAI falla
  - Throttle: 30 req/min
- **Sessions** `GET /api/ai/tutor/sessions` — listado paginado (excluye JSONB `messages`)
- **End session** `PATCH /api/ai/tutor/sessions/{id}/end`
- Historial persistido en tabla `ai_chat_sessions` (messages JSONB, scoped por tenant)

---

### ✅ Progreso del estudiante
- Se actualiza automáticamente al enviar examen (`StudentProgressService`)
- `mastery_percentage` calculado con AVG() en SQL (no en memoria)
- `overall_average` y `exams_completed_count` sincronizados en `students`
- **List** `GET /api/student-progress`
- **Me** `GET /api/student-progress/me`
- **Upsert manual** `POST /api/student-progress`
- **Recalcular** `POST /api/student-progress/recalc`

---

### ✅ Exámenes disponibles para el estudiante
- `GET /api/students/me/available-exams`
- Filtra: status=active, dentro de ventana, grupos del estudiante, intentos restantes
- Usa `withCount` — el filtro de intentos se ejecuta en BD, no en PHP

---

### ✅ Analíticas
- `GET /api/analytics/institution` — total/active students, exams completed, avg score %
- `GET /api/analytics/subjects` — exams count, enrolled students, avg mastery por materia
- `GET /api/analytics/students/{id}` — detalle completo (intentos, progreso, últimos 10 intentos)

---

### ✅ Reportes
- Resultados de examen `GET /api/reports/exams/{exam}/results`
- Exportar CSV `GET /api/reports/exams/{exam}/results.csv`
- Historial de estudiante `GET /api/reports/students/{id}/history`
- IDOR protegido: teacher solo accede a sus propios exámenes

---

### ✅ Recursos de estudio
- CRUD completo
- Ruta: `/api/study-resources`

---

### ✅ Calendario
- CRUD completo
- Ruta: `/api/calendar-events`

---

## 3. Brechas encontradas vs documento TFG

Las imágenes del documento `CTFG-DOC-18_Guia_para_Informe_Final_TFG 2025.docx` describen los siguientes flujos que han sido parcialmente o totalmente implementados:

---

### 3.1 Diagrama de secuencia del examen (image5)

| Paso | ¿Implementado? | Notas |
|------|---------------|-------|
| Crear examen (draft/programado) | ✅ | |
| Notificar disponibilidad al estudiante | ❌ | No hay evento ni notificación push/email |
| Iniciar examen (EnProgreso) | ✅ | `started_at` registrado |
| Temporizador activo | ✅ | `duration_minutes` validado en submit con 30 s de gracia |
| Pausar examen | ✅ | `PATCH /attempts/{id}/pause` — registra `paused_at` |
| Reanudar examen | ✅ | `PATCH /attempts/{id}/resume` — acumula `total_paused_seconds` |
| Enviar examen (Enviado) | ✅ | `submitted_at` registrado |
| Calificar automáticamente | ✅ | MC y TF auto-graded; SA/Essay → needs_review |
| Mostrar resultados | ✅ | |
| IA genera recomendaciones personalizadas | ⚠️ | OpenAI en regenerate; plantillas estáticas en submit automático |

---

### 3.2 Tutor IA conversacional (image11)

**Lo que existe:**
- `POST /api/ai/tutor/chat` — chat con historial por sesión, contexto del estudiante (perfil + progreso por materia)
- `GET /api/ai/tutor/sessions` — historial de sesiones paginado
- `PATCH /api/ai/tutor/sessions/{id}/end` — cerrar sesión
- `learning_style` en perfil del estudiante adapta el prompt del tutor
- Historial persistido en `ai_chat_sessions.messages` (JSONB, max 60 mensajes)
- `POST /api/ai/generate` — prompt libre → respuesta OpenAI
- `POST /api/exam-attempts/{id}/recommendations/regenerate` — recomendaciones post-examen

**Lo que falta:**
- [ ] Flujos interactivos estructurados (practicar / estudiar / preguntar / ver diagnóstico)
- [ ] Carga automática del diagnóstico al iniciar sesión del tutor
- [ ] Generación de ejemplos por tipo de aprendizaje (gráficos/audio alternativo)

---

### 3.3 Casos de uso (image10)

| Caso de uso | Rol | Estado |
|-------------|-----|--------|
| Ver Exámenes | Estudiante | ✅ |
| Realizar Examen | Estudiante | ✅ |
| Ver Resultados | Estudiante | ✅ |
| Iniciar Sesión | Todos | ✅ |
| Consultar IA Tutor | Estudiante | ⚠️ Chat contextual con historial; faltan flujos guiados |
| Ver Progreso | Estudiante | ✅ |
| Acceder Calendario | Estudiante | ✅ |
| Solicitar Recursos | Estudiante | ✅ |
| Gestionar Estudiantes | Profesor/Admin | ✅ |
| Crear Exámenes | Profesor/Admin | ✅ |
| Asignar Grupos | Profesor/Admin | ✅ |
| Ver Analíticas | Profesor/Admin | ✅ |
| Generar Reportes | Profesor/Admin | ✅ |
| Configurar Sistema | Admin | ❌ No existe endpoint |
| Revisar Resultados | Profesor/Admin | ✅ |

---

### 3.4 Modelo de datos (image3)

El diagrama ER del documento muestra una entidad **`StudentSubject`** (inscripción explícita estudiante-materia) que no existe en el schema actual. Lo más cercano es `student_progress` (progreso por materia) pero no cubre el caso de "inscripción" formal a una materia.

---

## 4. Bugs activos

> ✅ Todos los bugs identificados hasta el 09/05/2026 han sido corregidos.  
> Ver detalle completo en `INFORME_BUGS_ABRIL_2026.md`.

### Bugs corregidos en sesión 21/04/2026

| # | Archivo(s) | Descripción | Estado |
|---|-----------|-------------|--------|
| B1 | `AiRecommendationController.php` | Filtro `where('type')` apuntaba a columna inexistente | ✅ |
| B2 | `Student.php` | Campo `year` faltaba en `$fillable` y `$casts` | ✅ |
| B3 | `AiRecommendation.php` | Campo JSONB `resource` sin cast `array` | ✅ |
| B4 | `AiRecommendation.php` | `recommendation_type` sin cast a enum PHP | ✅ |
| B5 | `ExamAttempt.php` | `grade_status` sin cast a enum PHP | ✅ |
| B6 | `StudentAnswer.php` | `review_status` sin cast a enum PHP | ✅ |
| B7 | `CalendarEvent.php` | `event_type` sin cast a enum PHP | ✅ |
| B8 | `ExamController.php` | Activar examen con `available_until` expirado no era rechazado | ✅ |
| B9 | `AiRecommendationController.php` | N+1 en `myRecommendations()`; query duplicada en `review()` | ✅ |
| B10 | `01_schema.sql` | `adecuacion_type` era `text` en DB pero ENUM en PHP | ✅ |
| B11 | `ExamGradingService.php` | Comparación de IDs `bigserial` mediante `(int)` | ✅ |
| B12 | `ExamGradingService.php` | `correct_answer_snapshot` nunca se escribía al calificar | ✅ |
| B13 | `app/Enums/CalendarTargetType.php` | Enum sin uso — eliminado | ✅ |
| B14 | `QuestionController.php` | `QuestionType::Essay` sin lógica de validación ni calificación | ✅ |

### Correcciones adicionales (29/04/2026)

| # | Archivo(s) | Descripción | Estado |
|---|-----------|-------------|--------|
| C1 | `student_answers` | `review_status` era `varchar` en DB — convertido a ENUM PostgreSQL nativo | ✅ |
| C2 | `TenantScoped.php` | Silencioso en HTTP sin tenant_id — ahora lanza `RuntimeException` | ✅ |
| C3 | `.env.example` | Faltaba `OPENAI_REQUEST_TIMEOUT=15` | ✅ |

### Correcciones y mejoras (09/05/2026) — Tests de integración Niveles 1-6

| # | Archivo(s) | Descripción | Estado |
|---|-----------|-------------|--------|
| D1 | `Exam.php` | Método `syncGroups()` agregado; usa `syncWithPivotValues` para pasar `institution_id` al pivot `exam_targets` (antes violaba NOT NULL) | ✅ |
| D2 | `ExamController.php` | `groups()->sync()` → `syncGroups()` en `store()` y `update()` | ✅ |
| D3 | `ExamGradingService.php` | `selectedOptions()->sync()` → `syncWithPivotValues(['institution_id'])` en pivot `student_answer_options` | ✅ |
| D4 | `ExamAttemptController.php` | IDOR en `show()` retornaba 403 en vez de 404; `diffInSeconds()` retornaba float (Carbon 3.x) — cast a `(int)` | ✅ |
| D5 | `SetTenantFromAuth.php` | Resuelve usuario vía `auth()->guard('sanctum')->user()` para funcionar antes de `auth:sanctum` | ✅ |
| D6 | `bootstrap/app.php` | `SetTenantFromAuth` prepended al grupo `api` para ejecutarse antes de `SubstituteBindings` | ✅ |
| D7 | `routes/api.php` | Eliminado middleware `tenant` redundante de las rutas (ahora en grupo `api`) | ✅ |
| D8 | `config/database.php` | `'timezone' => 'UTC'` agregado en conexión `pgsql` (evita desfase de 6h con server en UTC-6) | ✅ |
| D9 | `Student.php` | `->withTimestamps(false)` en `groups()` generaba columna vacía `""` en SQL de PostgreSQL — eliminado | ✅ |
| D10 | `routes/api.php` | Ruta literal `GET /students/me/subjects` movida antes de la paramétrica `/{student_user_id}/subjects` (evitaba que `me` matcheara como ID) | ✅ |
| D11 | `AiTutorService.php` | `Collection::takeLast()` no existe en Laravel — reemplazado por `take(-n)` | ✅ |
| D12 | `AnalyticsController.php` | Claves de respuesta renombradas: `average_score_percentage` → `average_score_pct`, `total_attempts` → `attempts_count`, `progress_by_subject` → `progress` | ✅ |
| D13 | `ReportController.php` | Clave `attempts` → `results` en respuesta de `examResults()` | ✅ |
| D14 | `InstitutionFactory.php` | Código `INST-####` solo tenía 10,000 variantes → colisiones intermitentes en suite completa. Ahora usa UUID de 8 chars (4 billones de variantes) | ✅ |
| D15 | `01_schema.sql` / migración | FKs de `exams`: `subject_id` → `ON DELETE CASCADE`; `created_by_teacher_id` → nullable + `ON DELETE SET NULL` | ✅ |

---

## 5. TODO priorizado

### ✅ Prioridad Alta — Completado (21/04/2026)

- [x] Validar `duration_minutes` en submit — `ExamAttemptRulesService`, 30 s de gracia
- [x] `'resource'` en fillable y cast array de `AiRecommendation`
- [x] Aplicar `randomize_questions` — `QuestionController::index()`
- [x] Actualizar `students.overall_average` y `exams_completed_count` — `syncStudentStats()`

---

### ✅ Prioridad Media — Completado (29/04/2026)

- [x] **Pausa y reanudación del examen**
  - `PATCH .../pause` y `.../resume`; tiempo de pausa descontado del deadline

- [x] **Campo `learning_style` en perfil del estudiante**
  - ENUM PostgreSQL nativo; adapta el prompt del tutor IA

- [x] **Tutor IA conversacional**
  - Chat con historial por sesión (`ai_chat_sessions`), contexto del estudiante, fallback amigable

- [x] **Historial de conversación del tutor**
  - Tabla `ai_chat_sessions` (messages JSONB); límite 60 mensajes almacenados

- [x] **Exámenes disponibles para el estudiante**
  - `GET /api/students/me/available-exams` — filtrado completo en BD con `withCount`

- [x] **Analíticas agregadas para profesor/admin**
  - `/analytics/institution`, `/analytics/subjects`, `/analytics/students/{id}`

---

### ✅ Optimizaciones de rendimiento — Completado (29/04/2026)

> Objetivo: soportar 200 usuarios concurrentes con tiempos de respuesta razonables.

- [x] **`ExamGradingService`** — eliminadas N queries en loop de corrección (opciones ya cargadas con eager load)
- [x] **`StudentProgressService::recalcFromAttempts()`** — AVG movido a SQL; ya no carga todos los intentos en RAM
- [x] **`StudentProgressService::syncStudentStats()`** — AVG de progreso movido a SQL
- [x] **`GroupController::addStudents()`** — loop de 2N queries reemplazado por 1 upsert (`INSERT ON CONFLICT`)
- [x] **`GroupController::recountStudents()`** — `COUNT + save()` reemplazado por `DB::table->update()` directo
- [x] **`AiTutorController::sessions()`** — columna `messages` JSONB excluida del listado
- [x] **`AnalyticsController::subjects()`** — `Subject::all()` reemplazado por `select('id','name')`
- [x] **`SubjectController::destroy()`** — `count() > 0` → `exists()`
- [x] **`QuestionController::destroy()`** — `count()` acotado con `limit(2)`
- [x] **Índices de rendimiento fase 2** — `idx_ai_recs_regen_filter`, `idx_answers_review_status`, `idx_attempts_grade_status`, `idx_chat_sessions_active`

---

### ✅ Brechas vs TFG — Completado (09/05/2026)

- [x] **Rate limiting en `/ai/generate`** — `throttle:20,1` agregado (era el único endpoint de IA sin throttle)
- [x] **Headers de seguridad HTTP** — middleware `SecurityHeaders` (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) aplicado a grupos `api` y `web`
- [x] **Código muerto eliminado** — `NameController.php` y `StoreNameRequest.php` borrados
- [x] **Flujos interactivos del tutor IA** — parámetros `mode` (`ask`/`explain`/`practice`) y `topic` en `POST /ai/tutor/chat`; nuevo `GET /ai/tutor/diagnosis` con resumen IA del progreso
- [x] **Adecuación curricular en exámenes** — `ExamAttemptRulesService` aplica tiempo extra: `acceso` → ×1.25, `evaluacion` → ×1.50
- [x] **Validación de output IA** — `AiOutputValidator` verifica longitud y patrones PII; aplicado en `AiController` y `AiTutorService`
- [x] **Whitelist de URLs en recursos IA** — `config/ai_resources.php` con dominios permitidos; validación en `StudyResourceController` (store/update) y en `AiRecommendationService::extractResource()`
- [x] **Métricas de uso del tutor** — `GET /reports/ai/tutor-usage` con total sesiones/mensajes, estudiantes únicos, top tipos de recomendación y top estudiantes por uso

---

### ✅ Prioridad Baja — Completado (09/05/2026)

- [x] **Validación de `subject_id` en ExamController**
  - `Rule::exists('subjects', 'id')` en `store()` y `update()`

- [x] **Endpoint de configuración del sistema**
  - `GET /api/system/config` — admin y teacher
  - `PUT /api/system/config` — solo admin
  - Campos: `timezone`, `language`, `logo_url`, `max_exam_duration`, `allow_registration`, `contact_email`
  - Almacenado como JSONB en `institutions.settings`; defaults en `Institution::$defaultSettings`

- [x] **`StudentSubject` — inscripción explícita a materias**
  - Tabla `student_subjects (institution_id, student_user_id, subject_id, enrolled_at)` con UNIQUE por par
  - Modelo `StudentSubject`, relación `Student::subjects()` (belongsToMany)
  - `GET /api/students/{id}/subjects` — listado (admin/teacher)
  - `POST /api/students/{id}/subjects` — inscribir (admin/teacher)
  - `DELETE /api/students/{id}/subjects/{subject}` — desinscribir (admin/teacher)
  - `GET /api/students/me/subjects` — mis materias (student)

---

### ✅ Tests de integración y cascada de eliminación — Completado (09/05/2026)

- [x] **Suite de integración completa** — 60 nuevos tests (Niveles 1-6) que cubren el flujo end-to-end, RBAC, ciclo de vida del estudiante, tutor IA, analíticas y configuración del sistema. Total: **142 tests, 423 assertions**.
- [x] **Cascada de eliminación en Subject** — `DELETE /subjects/{id}` elimina en cascada exámenes → preguntas → opciones → intentos → respuestas; también inscripciones y progreso. Antes bloqueaba si el subject tenía exámenes.
- [x] **Cascada de eliminación en Group** — `DELETE /groups/{id}` elimina membresías y asignaciones de examen en cascada. Antes bloqueaba si había estudiantes activos.
- [x] **Eliminación de User** — nueva ruta `DELETE /users/{id}` (admin). Revoca tokens y elimina en cascada el perfil student y toda su actividad. Los exámenes del profesor quedan con `created_by_teacher_id = NULL`.
- [x] **Migración de FKs** — `exams.subject_id ON DELETE CASCADE` y `exams.created_by_teacher_id ON DELETE SET NULL` (nullable). Antes ambos eran RESTRICT implícito.

---

> **Backend completado al 100%** — todos los módulos, bugs, seguridad, optimizaciones, brechas TFG, tests de integración y comportamiento de eliminación implementados.
> Quedan pendientes: frontend (React/Next.js), banco de ítems (60+ preguntas), piloto con usuarios, documento académico.

---

## 6. Referencia de endpoints existentes

### Públicos (sin auth)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/ping` | Health check |
| POST | `/api/register` | Registro de usuario |
| POST | `/api/auth/login` | Login |
| POST | `/api/password/forgot` | Solicitar reset |
| POST | `/api/password/verify` | Verificar 

 |
| POST | `/api/password/reset` | Resetear contraseña |

### Autenticados — Solo estudiante
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/students/me` | Mi perfil |
| GET | `/api/students/me/available-exams` | Exámenes disponibles para mí |
| POST | `/api/exams/{exam}/attempts/start` | Iniciar intento |
| POST | `/api/exams/{exam}/attempts/{attempt}/submit` | Enviar intento |
| PATCH | `/api/exams/{exam}/attempts/{attempt}/pause` | Pausar intento |
| PATCH | `/api/exams/{exam}/attempts/{attempt}/resume` | Reanudar intento |
| GET | `/api/exams/{exam}/attempts/{attempt}` | Ver intento |
| POST | `/api/exam-attempts/{attempt}/recommendations/regenerate` | Regenerar recomendaciones IA |
| GET | `/api/student-progress/me` | Mi progreso por materia |
| GET | `/api/ai-recommendations/me` | Mis recomendaciones IA |
| POST | `/api/ai/tutor/chat` | Chat con tutor IA |
| GET | `/api/ai/tutor/sessions` | Mis sesiones del tutor |
| PATCH | `/api/ai/tutor/sessions/{id}/end` | Finalizar sesión del tutor |

### Autenticados — Admin, Profesor y Estudiante (lectura compartida)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/exams` | Lista exámenes |
| GET | `/api/exams/{exam}` | Ver examen |
| GET | `/api/exams/{exam}/questions` | Ver preguntas |
| GET | `/api/subjects` | Lista materias |
| GET | `/api/subjects/{subject}` | Ver materia |
| GET | `/api/study-resources` | Lista recursos |
| GET | `/api/study-resources/{id}` | Ver recurso |
| GET | `/api/calendar-events` | Lista eventos |
| GET | `/api/calendar-events/{id}` | Ver evento |

### Autenticados — Admin y Profesor
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/users` | Lista usuarios |
| GET/PUT/PATCH | `/api/users/{id}` | Ver/editar usuario |
| PATCH | `/api/users/{id}/status` | Cambiar estado usuario |
| PATCH | `/api/users/{id}/reset-password` | Resetear contraseña |
| DELETE | `/api/users/{id}` | Eliminar usuario (solo admin) — cascada a student, intentos, etc. |
| GET | `/api/students` | Lista estudiantes |
| GET/PUT | `/api/students/{id}` | Ver/editar estudiante |
| PATCH | `/api/students/{id}/status` | Cambiar estado estudiante |
| GET | `/api/students/bulk-upload/template` | Plantilla CSV de carga masiva |
| POST | `/api/students/bulk-upload` | Carga masiva (CSV/XLSX) |
| GET/POST/PUT/DELETE | `/api/groups` | CRUD grupos |
| POST | `/api/groups/{group}/students` | Asignar estudiantes a grupo |
| DELETE | `/api/groups/{group}/students` | Retirar estudiantes de grupo |
| POST/PUT/DELETE | `/api/subjects` | Mutaciones de materias |
| POST/PUT/DELETE | `/api/exams` | Mutaciones de exámenes |
| POST/PUT/DELETE | `/api/exams/{exam}/questions` | Mutaciones de preguntas |
| PUT/DELETE | `/api/questions/{question}` | Update/delete pregunta |
| GET | `/api/exam-attempts/{attempt}/answers` | Ver respuestas de intento |
| PATCH | `/api/student-answers/{answer}/review` | Revisar respuesta SA/Essay |
| GET | `/api/student-progress` | Lista progreso de estudiantes |
| POST | `/api/student-progress` | Upsert manual de progreso |
| POST | `/api/student-progress/recalc` | Recalcular progreso desde intentos |
| POST/PUT/DELETE | `/api/study-resources` | Mutaciones de recursos |
| POST/PUT/DELETE | `/api/calendar-events` | Mutaciones de eventos |
| POST | `/api/ai/generate` | Generar texto con IA (prompt libre) |
| GET | `/api/ai-recommendations` | Lista recomendaciones |
| GET | `/api/ai-recommendations/{id}` | Ver recomendación |
| GET | `/api/reports/exams/{exam}/results` | Reporte de examen |
| GET | `/api/reports/exams/{exam}/results.csv` | CSV de resultados |
| GET | `/api/reports/students/{id}/history` | Historial estudiante |
| GET | `/api/analytics/institution` | Estadísticas institucionales |
| GET | `/api/analytics/subjects` | Rendimiento por materia |
| GET | `/api/analytics/students/{id}` | Detalle analítico de estudiante |

### Autenticados — Solo Estudiante (adicionales)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/students/me/subjects` | Mis materias inscritas |
| GET | `/api/ai/tutor/diagnosis` | Diagnóstico IA de mi progreso |

### Autenticados — Admin y Profesor (materias del estudiante)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/students/{id}/subjects` | Materias inscritas de un estudiante |
| POST | `/api/students/{id}/subjects` | Inscribir estudiante a materia |
| DELETE | `/api/students/{id}/subjects/{subject}` | Desinscribir estudiante de materia |

### Autenticados — Solo Admin
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/institutions` | Lista instituciones |
| GET/PUT | `/api/institutions/{id}` | Ver/editar institución |
| PATCH | `/api/institutions/{id}/toggle` | Activar/desactivar institución |

---

*Documento actualizado el 09/05/2026.*
