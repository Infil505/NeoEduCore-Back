# NeoEduCore — Estado del proyecto y pendientes
**Última actualización:** 23 de junio de 2026  
**Rama activa:** Darwin  
**Tests:** 148 pasando / 0 fallando

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
- SMTP — correos (reset de contraseña + alta por carga masiva). **Asíncronos vía cola** (`QUEUE_CONNECTION=database`). Formas de procesarla (todas gratis):
  - **Local:** `composer run dev` (levanta `serve` + `queue:listen` en un comando), o `php artisan queue:work` en otra terminal. (Nota: se quitó `pail` del script porque requiere la extensión `pcntl`, no disponible en Windows.)
  - **Producción con cron** (hosting gratuito sin daemons): una línea `* * * * * php artisan schedule:run` — el scheduler drena la cola cada minuto (definido en `routes/console.php`).
  - **Sin cola:** `QUEUE_CONNECTION=sync` (cero configuración, pero el envío bloquea la request).

**Multi-tenancy:** cada request autenticado inyecta `institution_id` via `SetTenantFromAuth`, activando el scope `TenantScoped` en todos los modelos. Si el scope detecta contexto HTTP sin `institution_id`, lanza `RuntimeException` (detección temprana de bugs de configuración).

**RBAC:** middleware `RequireRole` valida roles por ruta. Roles: `admin`, `teacher`, `student`, `parent`.

---

## 2. Estado actual por módulo

### ✅ Autenticación
- **Register** `POST /api/register` — **solo admin** (desde 23/06/2026). Crea un usuario en la institución del admin; perfil `Student` automático si es estudiante. Acepta `user_type` o lo infiere por email. No devuelve token. El primer admin se crea con el seeder
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

### Sesión 23/06/2026 — Bug de seeding + refactor de permisos

| # | Archivo(s) | Descripción | Estado |
|---|-----------|-------------|--------|
| E1 | `AuthController.php` | `student_code` se generaba con `substr($user->id, 0, 8)`. Al ser UUIDv7 (ordenado por tiempo) los primeros 8 hex son el prefijo de timestamp → colisión de la constraint `unique(student_code)` para estudiantes creados en la misma ventana de ~65 s. Ahora usa el UUID completo (único por usuario). Detectado sembrando 60 estudiantes con k6 | ✅ |
| E2 | `routes/api.php`, `AuthController.php` | `POST /register` pasa a **admin-only** (`auth:sanctum` + `role:admin`). Fuerza `institution_id` del admin, acepta `user_type` explícito (o lo infiere por email) y ya no devuelve token. El primer admin se crea con el seeder | ✅ |
| E3 | `routes/api.php` | Permisos de `/users` reorganizados: lectura (`index`/`show`) admin+teacher; mutaciones (`update`, `status`, `reset-password`, `delete`) **solo admin**. Antes un teacher podía borrar/editar a cualquier usuario, incl. admins | ✅ |
| E4 | `UserController.php` | `/users` no usaba `TenantScoped` (User es público en login) → exponía usuarios de otras instituciones. Añadido filtro por institución en `index` y guard 404 cross-tenant en `show/update/setStatus/destroy/resetPassword` | ✅ |
| E5 | `tests/`, `k6/seed_users.js` | Tests actualizados al nuevo flujo de register (+2 nuevos: requiere admin / requiere auth). Script k6 adaptado: login admin → alta con token + `user_type` | ✅ |
| E6 | `StudentController.php`, `PasswordSetupService.php`, `routes/api.php` | **Carga masiva mejorada:** ahora crea cuentas de usuario (columnas `full_name`/`email`) además de perfiles `Student`, y encola para cada nuevo usuario un correo para establecer su contraseña (fuera de la transacción). Todas las búsquedas de usuario filtran por institución del actor (cierra el IDOR cross-tenant). Normaliza celdas vacías (`''` → NULL) — corrige bug latente con `birth_date` vacío. `bulk-upload` pasa a **admin-only** por crear cuentas. Respuesta añade `users_created`, `emails_queued`, `email_failures`. +4 tests | ✅ |
| E7 | `.env`, `PasswordResetMail.php`, `ForgotPasswordController.php`, `emails/password-reset.blade.php`, migraciones `jobs`/`failed_jobs`, `01_schema.sql` | **Correos asíncronos (cola).** `QUEUE_CONNECTION=database` + tablas `jobs`/`failed_jobs`; `PasswordResetMail` ahora es `ShouldQueue` y se envía con `->queue()` → la request ya no se bloquea esperando al SMTP. **Requiere worker:** `php artisan queue:work`. De paso se corrigió un bug latente: la vista del correo era una copia del formulario de reset (usaba `$email`/`$token` no provistos) → fallaba al renderizar, pero el `try/catch` de `sendResetLink` lo ocultaba. Reescrita como email real con enlace a `password.reset.form`. El `Mailable` ahora arma la URL correcta desde el token | ✅ |
| E8 | `Factories/Students/StudentFactory.php`, `Factories/Admin/StudentFactory.php` | `student_code` de los factories pasó de `STU-####` (10⁴, flaky) a `STU-##########` (10¹⁰) — elimina colisiones de unicidad intermitentes al crear muchos estudiantes en la suite | ✅ |
| E13 | `composer.json/lock`, `Dockerfile`, `.dockerignore`, `DEPLOY_COOLIFY.md`, `routes/console.php` | **Preparación de despliegue (DO + Coolify).** (4) `composer.json` desincronizado corregido: `phpspreadsheet` `^2.4`→`^5.4` (coincide con el lock real 5.4.0). (5) `laravel/octane ^2.17` añadido al lock (framework intacto en v12.24 vía `-m`). (6) `Dockerfile` (FrankenPHP + Octane, opcache+JIT), `.dockerignore` y guía `DEPLOY_COOLIFY.md` (app + worker `queue:work` + scheduled `schedule:run` + migrate post-deploy + region SFO3 + session pooler 5432 + `BCRYPT_ROUNDS=10`). **Nota:** octane no se extrae en local por un bloqueo del indexador/antivirus de Windows en `vendor/`; el contenedor (Linux) lo instala sin problema. | ✅ |
| E12 | comando `schema:dump-sql`, `01_schema.sql` (regenerado), migración `fix_pivot_uuid_defaults_and_obsolete_check`, 3 tests | **Causa de fondo resuelta: fuente única de verdad.** `database/sql/01_schema.sql` (que cargan los tests) pasa a ser un **artefacto GENERADO** desde la BD real (= migraciones) con `php artisan schema:dump-sql` (pg_dump + limpieza). Ya no puede divergir de las migraciones. Alinear ambos destapó **3 bugs reales de prod** que el SQL a mano ocultaba: (1) pivotes `group_students`/`exam_targets`/`student_answer_options` sin `DEFAULT gen_random_uuid()` en `id` → insert sin id fallaba; (2) `review_status` con CHECK obsoleto `('auto_graded','needs_review')` sin `reviewed` (leftover de C1) → revisar respuesta daba 500; (3) `group_students.institution_id` NOT NULL (integridad de tenant) que los tests omitían vía `attach()`. **Workflow:** cambiar esquema con migración → `migrate` → `schema:dump-sql` → commit de ambos. Requiere `PG_DUMP_PATH` en `.env`. | ✅ |
| E11 | migración `convert_enum_columns_to_native_pg_enums` | **Auditoría completa de enums.** 9 de 12 columnas enum eran `varchar` en la BD real (migraciones usaban `$table->enum()` = varchar+CHECK; `01_schema.sql` usa enum PG nativo → **drift schema/migraciones**). Riesgo: valor inválido → 500 al hidratar. **Bug concreto encontrado:** `students.status` tenía CHECK `('active','inactive')` → `PATCH /students/{id}/status` con `suspended` (válido en la app) daba **500 Check violation**. Fix: migración que convierte las 9 columnas a enums PG nativos (set completo y correcto), quitando los CHECK heredados. 0 datos dañados en BD. Verificado: suspender alumno → 200, insert inválido rechazado. **Meta-causa:** los tests construyen el esquema desde `01_schema.sql` (correcto) y no desde migraciones, por eso pasaban en verde mientras la BD real fallaba | ✅ |
| E10 | datos BD, `CoreTablesSeeder.php`, migración `convert_recommendation_type_to_enum` | **Bug reportado (exámenes/recomendaciones): 500 al listar recomendaciones.** `GET /ai-recommendations` y `/ai-recommendations/me` daban `ValueError: "study_plan"/"support_resource" is not a valid backing value for enum AiRecommendationType`. Causa: el `CoreTablesSeeder` insertaba `study_plan`/`support_resource`, que no son valores del enum (`strength,weakness,resource,action`), y la columna era `varchar` (la migración original) en vez del enum PG → permitía colar basura que reventaba al hidratar el modelo. Fix: (1) datos corregidos (study_plan→action, support_resource→resource), (2) seeder corregido, (3) columna convertida a enum PG nativo → ahora un valor inválido falla en el INSERT. Detectado con el flujo completo de examen (start→submit→ver recomendaciones) | ✅ |
| E9 | `.env` (`DB_PORT`) | **Stress test (k6) detectó 500 intermitentes bajo carga.** La BD es Supabase vía pooler en modo *transaction* (`:6543`), que NO conserva prepared statements entre transacciones → `prepared statement does not exist`. Cambiado al pooler en modo *session* (`:5432`), que mantiene afinidad de conexión. (Nota: `EMULATE_PREPARES=true` también lo evita pero rompe los booleanos en PostgreSQL, así que se descartó.) **Pendiente de arquitectura para 200+ concurrentes** — ver sección de escalado | ✅ (dev) |

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

### 📌 Pendientes abiertos (anotados 23/06/2026)

- [x] ~~**`AuthController::register` sin transacción**~~ → **Resuelto 26/06/2026.** `User::create` + `Student::create` envueltos en `DB::transaction()` (atómico, no quedan huérfanos). De paso `/students/me` degrada elegante (200 con `data:null` en vez de 404).
- [x] ~~**Rol `parent` sin superficie de API**~~ → **Decidido 26/06/2026.** Queda RESERVADO para un futuro portal de acudientes, pero se quitó la auto-detección por email (un email ya no crea un `parent` accidental sin rutas). El admin puede crearlo explícito con `user_type=parent`.
- [x] ~~**`students/bulk-upload` confía en `user_id` sin verificar tenant**~~ → **Resuelto 23/06/2026 (E6).** Todas las búsquedas de usuario en bulk-upload ahora filtran por `institution_id` del actor.

---

> **Backend prácticamente completo** — módulos, bugs, seguridad, optimizaciones, brechas TFG, tests de integración y comportamiento de eliminación implementados. Quedan los pendientes abiertos de arriba (no bloqueantes).
> Fuera del código: frontend (React/Next.js), banco de ítems (60+ preguntas), piloto con usuarios, documento académico.

---

## 6. Referencia de endpoints existentes

### Públicos (sin auth)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/ping` | Health check |
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
| GET | `/api/users` | Lista usuarios (admin+teacher, scoped por institución) |
| GET | `/api/users/{id}` | Ver usuario (admin+teacher) |
| POST | `/api/register` | **Alta de usuario (solo admin)** — en la institución del admin |
| GET | `/api/students/bulk-upload/template` | Plantilla CSV de carga masiva (**solo admin**) |
| POST | `/api/students/bulk-upload` | Carga masiva CSV/XLSX (**solo admin**) — crea cuentas + envía correo de contraseña |
| PUT/PATCH | `/api/users/{id}` | Editar usuario (**solo admin**) |
| PATCH | `/api/users/{id}/status` | Cambiar estado usuario (**solo admin**) |
| PATCH | `/api/users/{id}/reset-password` | Resetear contraseña (**solo admin**) |
| DELETE | `/api/users/{id}` | Eliminar usuario (**solo admin**) — cascada a student, intentos, etc. |
| GET | `/api/students` | Lista estudiantes |
| GET/PUT | `/api/students/{id}` | Ver/editar estudiante |
| PATCH | `/api/students/{id}/status` | Cambiar estado estudiante |
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
