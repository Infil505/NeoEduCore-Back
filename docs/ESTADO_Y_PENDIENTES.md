# NeoEduCore — Estado del proyecto y pendientes
**Última actualización:** 8 de agosto de 2026  
**Rama activa:** Darwin  
**Tests:** 305 pasando / 0 fallando

---

## Índice
1. [Arquitectura general](#1-arquitectura-general)
2. [Estado actual por módulo](#2-estado-actual-por-módulo)
3. [Brechas encontradas vs documento TFG](#3-brechas-encontradas-vs-documento-tfg)
4. [Bugs activos](#4-bugs-activos)
5. [TODO priorizado](#5-todo-priorizado)
6. [Referencia de endpoints existentes](#6-referencia-de-endpoints-existentes)
7. [Límites de peticiones](#7-límites-de-peticiones-07082026)
8. [Ciclo de vida de la cuenta](#8-ciclo-de-vida-de-la-cuenta-07082026)
9. [Entregables del TFG fuera del código](#9-entregables-del-tfg-fuera-del-código)

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
- CRUD completo. **Lectura** abierta a admin/teacher/student; **todas las mutaciones son admin-only**
- Ruta: `/api/subjects` — `GET` admite `search` y `per_page`, y devuelve `exams_count`
- **Nombre único por institución**, ignorando mayúsculas y espacios: índice funcional `UNIQUE (institution_id, lower(btrim(name)))`. Coexisten "Matemática 1er grado" y "Matemática 2do grado"; no dos "Matemática"
- `DELETE /api/subjects/{id}` — cascada DB: exámenes → preguntas → opciones → intentos → respuestas. También elimina inscripciones (`student_subjects`) y progreso por materia.

---

### ✅ Grupos
- CRUD completo + asignación/baja de estudiantes (upsert batch)
- Ruta: `/api/groups`
- `POST`/`DELETE /api/groups/{group}/students` — alta y baja **lógica** (`left_at`) por lista de ids. La baja conserva la fila como historial
- `student_count` se recalcula en cada alta/baja (RN-STU-012)
- `DELETE /api/groups/{id}` — cascada DB: membresías (`group_students`) y asignaciones de examen (`exam_targets`). Los estudiantes y exámenes NO se eliminan.

---

### ✅ Ciclo académico — reasignación masiva y repitentes
- Rutas (**admin-only**, `throttle:10,1`): `POST /api/bulk/reassign-group`, `POST /api/bulk/reassign-subjects`, `POST /api/bulk/reset-progress`
- Lógica en `app/Services/Academic/BulkReassignmentService.php`; el controlador solo valida y traduce a HTTP
- Cubre el ciclo completo de fin de año: promoción, repitentes, alumnos nuevos y plan de materias
- **Repitentes:** `reset-progress` marca `student_progress.reset_at`, y `StudentProgressService::recalcFromAttempts` ignora los intentos anteriores al corte. Sin esa marca el reseteo se desharía en el siguiente examen. El historial de intentos **no** se borra
- Todo transaccional (lote entero o nada); los ids desconocidos vuelven en `skipped` sin abortar
- Contrato y **receta paso a paso de la promoción de fin de año** en la [sección 6](#6-referencia-de-endpoints-existentes)

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
  - Llama GPT-4o-mini; límite: la generación automática del submit + 3 regeneraciones
  - El cupo es **por intento**: `ai_recommendations` no tiene `attempt_id`, así que se
    cuentan los `generated_at` distintos desde la entrega de ese intento. Un segundo
    intento del mismo examen arranca con el cupo limpio
  - Fallback a plantillas estáticas si OpenAI falla
  - El texto devuelto por el modelo pasa por `AiOutputValidator` (PII, longitud y
    lista blanca de enlaces) antes de guardarse

⚠️ **Lo que genera el submit NO lo escribe la IA.** `AiRecommendationService::generateFromAttempt`
—el camino por defecto, el único que recorre un alumno que no pulsa nada— es un
`if/elseif/else` sobre el porcentaje con textos fijos, sin ninguna llamada a OpenAI.
La IA solo interviene si el estudiante pide explícitamente regenerar. Es una decisión
de arquitectura (no meter una llamada de segundos dentro de la transacción del submit,
que es el pico de concurrencia del sistema), pero **el informe la describe al revés**:
ver [§3.6](#36-el-tutor-lo-que-hace-vs-lo-que-el-informe-dice).

---

### ✅ Tutor IA conversacional
- **Chat** `POST /api/ai/tutor/chat` — chat con contexto del estudiante (perfil + progreso + historial)
  - Adapta el prompt según `learning_style` del estudiante
  - Contexto opcional: `subject_id` y `exam_id` (ambos validados contra lo que el
    alumno puede ver; el examen vale si lo rindió **o** si hoy lo tiene disponible)
  - Limita historial a 60 mensajes almacenados, 20 mensajes de contexto a OpenAI
  - Fallback con mensaje de error amigable si OpenAI falla
  - Throttle: 30 req/min por usuario + `ai-global` 120/min por institución
- **Diagnosis** `GET /api/ai/tutor/diagnosis` — resumen IA del progreso por materia
- **Sessions** `GET /api/ai/tutor/sessions` — listado paginado (excluye JSONB `messages`)
- **End session** `PATCH /api/ai/tutor/sessions/{id}/end`
- Historial persistido en tabla `ai_chat_sessions` (messages JSONB, scoped por tenant)

🔒 **Nada que identifique al estudiante sale hacia OpenAI** (desde 08/08/2026). Viajan
grado, estilo de aprendizaje y porcentaje de dominio por materia; **no** el nombre.
El saludo personalizado lo pone el frontend, que ya sabe quién ha iniciado sesión.
Cubierto por `AiTutorPrivacyTest`.

🔒 **La lista blanca de dominios se aplica también al texto libre del tutor.** Un enlace
fuera de `config/ai_resources.php` se sustituye por `[enlace no permitido]` y el resto
de la respuesta se conserva.

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
Grupal (por examen):
- Resultados paginados `GET /api/reports/exams/{exam}/results`
- Exportar CSV `GET /api/reports/exams/{exam}/results.csv`
- Resumen para gráficos `GET /api/reports/exams/{exam}/summary`

Individual (por estudiante):
- Historial paginado `GET /api/reports/students/{id}/history`
- Exportar CSV `GET /api/reports/students/{id}/history.csv`
- Resumen para gráficos `GET /api/reports/students/{id}/summary?points=`

- IDOR protegido: teacher solo accede a sus propios exámenes

**Reparto backend/frontend.** El backend expone datos; el PDF con gráficos lo
arma el frontend a partir de los `summary`. Los endpoints devuelven las series
ya agregadas y listas para pintar:

| Serie | Endpoint | Gráfico |
|---|---|---|
| `score_distribution` | examen | barras (histograma por rango de nota) |
| `performance_levels` | examen | pastel (4 niveles, categorías **ordenadas**) |
| `score_trend` | estudiante | líneas (del intento más antiguo al más reciente) |
| `subject_mastery` | estudiante | barras (dominio por materia) |

Los agregados se resuelven en **un solo SELECT** con `COUNT(*) FILTER`, no
recorriendo intentos en PHP; `QueryBudgetTest::test_exam_summary_cost_is_flat_per_attempt_count`
lo vigila.

`passing_percentage` (nota mínima de aprobación, 65 por defecto) vive en
`institutions.settings` y se edita con `PUT /api/system/config`. Separa aprobados
de reprobados y fija los cortes de los niveles de desempeño.

Estrategias del tutor (requisito [740] del informe):
- Estudiante `GET /api/reports/students/me/strategies`
- Docente/admin `GET /api/reports/students/{id}/strategies`
- Filtros `?subject_id=` y `?limit=` (por sección, 20 por defecto, máx. 100)

Devuelve las `ai_recommendations` agrupadas en las cuatro categorías del enum,
en orden narrativo: **Fortalezas → Aspectos por reforzar → Acciones sugeridas →
Recursos de apoyo**.

**Frontera de privacidad, fijada por [175] del informe.** El historial de chat
con el tutor (`ai_chat_sessions`) **no sale por ningún reporte**, ni siquiera en
el del propio alumno; solo salen las recomendaciones estructuradas. El docente
ve únicamente las nacidas de exámenes que él creó; el admin, las de su
institución. Está cubierto por
`TutorStrategiesTest::test_chat_history_never_appears_in_the_strategies_report`.

⚠️ **Cambio de comportamiento (05/08/2026) — `GET /api/exams`.** Al estudiante
se le aplica ahora `Exam::scopeVisibleTo`: solo exámenes **activos, dentro de su
ventana de disponibilidad y asignados a sus grupos**. `GET /api/exams/{id}` y
`GET /api/exams/{id}/questions` devuelven **404** si no lo superan (404 y no 403:
un 403 confirmaría que la prueba existe). Antes el alumno listaba el catálogo
completo, borradores incluidos, y con esos ids leía los enunciados de cualquier
examen antes de presentarlo. Además, al estudiante se le oculta el correo del
docente y la configuración del examen (`max_attempts`, `randomize_questions`,
`allow_review_after_submission`, `show_results_immediately`). Docente y admin no
cambian. Cubierto por `ExamVisibilityTest`.

> Si el frontend usaba `GET /api/exams` para la vista del alumno, ahora recibirá
> menos. El endpoint pensado para eso es `GET /api/students/me/available-exams`,
> que además indica los intentos ya entregados.

⚠️ **Cambio de comportamiento (05/08/2026).** `GET /api/ai-recommendations`
(docente) **se restringió**: antes listaba el `recommendation_text` de cualquier
alumno de la institución, incluidos exámenes ajenos; ahora aplica la misma regla
que `show()` y se limita a los exámenes propios del docente. Ninguna prueba
existente cubría el hueco. Si el frontend dependía del listado completo, hay que
usar una cuenta admin.

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
| IA genera recomendaciones personalizadas | ⚠️ | OpenAI **solo** en regenerate; plantillas estáticas en el submit automático, que es el camino por defecto. Ver [§3.6](#36-el-tutor-lo-que-hace-vs-lo-que-el-informe-dice) nº 1 |

---

### 3.2 Tutor IA conversacional (image11)

**Lo que existe:**
- `POST /api/ai/tutor/chat` — chat con historial por sesión, contexto del estudiante (perfil + progreso por materia)
- Modos `ask` / `explain` / `practice` + `topic` — cubren «no entendí» y «quiero practicar» de [173]
- `GET /api/ai/tutor/diagnosis` — resumen IA del progreso
- `GET /api/ai/tutor/sessions` — historial de sesiones paginado
- `PATCH /api/ai/tutor/sessions/{id}/end` — cerrar sesión
- `learning_style` en perfil del estudiante adapta el prompt del tutor
- Historial persistido en `ai_chat_sessions.messages` (JSONB, max 60 mensajes)
- `POST /api/ai/generate` — prompt libre → respuesta OpenAI
- `POST /api/exam-attempts/{id}/recommendations/regenerate` — recomendaciones post-examen

**Lo que falta:**
- [ ] Carga automática del diagnóstico al iniciar sesión del tutor (hoy es una llamada aparte que dispara el frontend)
- [ ] Generación de ejemplos por tipo de aprendizaje (gráficos/audio alternativo) — el estilo condiciona el *tono* del prompt, no el *formato* de la respuesta, que siempre es texto
- [ ] Registro de incidencias del tutor: los bloqueos por PII o enlace no permitido solo dejan `Log::warning`, sin tabla ni contador (ver §9.3)

> **Corregido el 08/08/2026:** este apartado listaba «flujos interactivos estructurados»
> como pendiente mientras la [sección 5](#-brechas-vs-tfg--completado-09052026) los daba
> por hechos desde el 09/05/2026. Existen: son los modos `mode`/`topic` y el endpoint de
> diagnóstico.

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
| Configurar Sistema | Admin | ✅ `GET/PUT /api/system/config` (añadido 26/06/2026) |
| Revisar Resultados | Profesor/Admin | ✅ |

---

### 3.4 Modelo de datos (image3)

El diagrama ER del documento muestra una entidad **`StudentSubject`** (inscripción explícita estudiante-materia). **Se implementó el 09/05/2026** (`student_subjects`, con `enrolled_at` y unicidad `(student_user_id, subject_id)`), pero el informe **no la documenta** como entidad. Ver `ANALISIS_MODELO_DATOS_TFG.md` §9.3, que la lista junto a `AiChatSession` entre las entidades ausentes del diagrama de clases.

---

### 3.5 Las relaciones: informe vs. sistema (revisado 08/08/2026)

Contraste hecho sobre `database/sql/01_schema.sql` (artefacto generado), los modelos
Eloquent y `ANALISIS_MODELO_DATOS_TFG.md`. **Lo relevante es que las cifras que ese
documento lleva al informe se quedaron desfasadas al aplicarse su propia migración.**

#### Cifras reales, para la figura de modelo de datos del informe

| Magnitud | Decía `ANALISIS_MODELO_DATOS_TFG.md` §9.2 | Real (tras H1 y H8) |
|---|---|---|
| Claves foráneas | 42 | **47** |
| Entidades | 17 | **15** |
| Tablas pivote | 4 | **4** |

- Las 42 eran el conteo **previo** a `align_fk_constraints_with_tfg_model`, que añadió 3;
  el 08/08 se añadieron 2 más (H8). §2 de aquel documento sí acierta al decir «36 en el
  boceto, 42 en la implementación» como foto histórica; §9.2 lo repetía como si fuera el
  estado actual. **Ya corregido allí.**
- **19 tablas de dominio** = 15 entidades + 4 pivotes (`group_students`, `exam_targets`,
  `student_answer_options`, `student_subjects`). Aparte, 5 de framework: `migrations`,
  `jobs`, `failed_jobs`, `password_reset_tokens`, `personal_access_tokens`.
- 16 tienen modelo Eloquent (las 15 entidades + `StudentSubject`); 3 pivotes no lo tienen.

Reproducible:

```bash
grep -c "ADD CONSTRAINT .* FOREIGN KEY" database/sql/01_schema.sql   # 47
grep -c "^CREATE TABLE public\." database/sql/01_schema.sql          # 24 (19 + 5)
```

#### Lo que sí se sostiene

**6 FK apuntan a `students(user_id)`** (`ai_chat_sessions`, `ai_recommendations`,
`exam_attempts`, `group_students`, `student_progress`, `student_subjects`). La
corrección del «grupo A» aguanta.

#### Coherencia de tenant — cerrada el 08/08/2026 (H8)

Hay **18 columnas `institution_id`**, y hasta esta sesión no eran homogéneas: dos sin FK
ninguna (`exam_attempts`, `student_progress`) y dos con `NO ACTION` mientras el resto
cascadeaba (`students`, `group_students`). Las cuatro venían de lo mismo: la migración de
alineación se ciñó a lo que declaraba el boceto del TFG, y el boceto no las cubría.

Estado final: **las 18 con FK**, 17 en `CASCADE` y solo `users.institution_id` en
`SET NULL` — deliberado, dar de baja un centro no borra a las personas.

Las dos que pasaron a `CASCADE` eran **inalcanzables** (no hay `DELETE /institutions`,
solo `toggle`). Se arreglaron por eso precisamente: el día que ese endpoint exista,
`NO ACTION` en `students` habría dado un 500, que es el fallo de abril otra vez.

En cambio **sí estaba vivo** el caso hermano de autoría, corregido el 08/08/2026 (H1):
`study_resources.created_by` era la última FK a `users(id)` en NO ACTION, y hacía que
`DELETE /api/users/{id}` devolviera **500** para cualquier docente que hubiera subido un
recurso. Mismo punto ciego que los 500 de abril: el escenario de prueba no montaba la
fila que rompe.

#### Relaciones declaradas que el código no usaba

`ai_chat_sessions.exam_id` estaba en la migración, en `$fillable`, en la relación
`exam()` y en el listado de sesiones, pero **ninguna ruta la escribía**: siempre NULL.
`ANALISIS_MODELO_DATOS_TFG.md` §3.4 la presenta al informe como relación real del
sistema. **Cableada el 08/08/2026** (H4): `POST /ai/tutor/chat` acepta `exam_id`.

#### Relaciones Eloquent que faltan respecto a las FK

No son fallos, pero si el diagrama de clases (§9.3 de aquel documento) se dibuja
leyendo los modelos, saldrá incompleto:

- `Student`, `AiChatSession`, `AiRecommendation`, `StudentAnswer`, `StudentProgress`,
  `Question`, `QuestionOption` y `ExamAttempt` tienen FK a `institutions` sin
  `belongsTo(Institution::class)`.
- `Institution` declara 4 `hasMany` de las 12 relaciones que le apuntan.
- `Subject` solo declara `hasMany(Exam::class)`.

---

### 3.6 El tutor: lo que hace vs. lo que el informe dice

La brecha más grande del proyecto, y la que peor se defiende si no se aborda de frente:
el tutor IA es el diferenciador del TFG.

| # | El informe dice | El sistema hace | Estado |
|---|---|---|---|
| 1 | [122][222][255][736] el tutor analiza los resultados y genera recomendaciones personalizadas | En el submit las genera un `if/elseif/else` sobre el porcentaje, **sin OpenAI**. La IA solo entra si el alumno pulsa regenerar | 🔴 Abierto — decisión de redacción |
| 2 | Figura 10 y [263]: «recursos personalizados» | Era el recurso más reciente del centro, igual para todos | ✅ Corregido (H3) |
| 3 | [173] «prohibirá datos personales» · [394] anonimización, Ley 8968, menores | El `full_name` del alumno viajaba en cada prompt a OpenAI | ✅ Corregido (H2) |
| 4 | [173] materiales externos «mediante una lista blanca» | La lista blanca no cubría el texto libre del chat | ✅ Corregido (H2) |
| 5 | [173] docentes con «solo métricas agregadas» y «reportes anónimos» | `tutor-usage` devolvía al docente un top 10 de alumnos **con nombre** | ✅ Corregido (H5) |
| 6 | [173] «registrará incidencias» + criterio «>75 % de mensajes que superen validación» | Solo `Log::warning`. Sin tabla, sin contador: **el criterio no es medible** | 🔴 Abierto — ya en §9.3 |
| 7 | [171] ítems con «tema, indicador y dificultad» · [222] «indicadores curriculares» | `questions` no tiene tema, indicador ni dificultad. La única señal es `mastery_percentage` **por materia** | 🔴 Abierto — decisión de alcance |
| 8 | [397] el sistema avisa de que la sugerencia viene de un modelo automatizado | La respuesta del chat es `{session_id, reply, message_count}`: no hay campo de aviso | 🟡 Abierto — contrato con el frontend |
| 9 | [173] recomendaciones «breves (2–4 oraciones)» | System prompt: «máximo 4 párrafos», 600 tokens (800 en práctica) | 🟡 Redacción |
| 10 | [222] «modelos GPT-4 (variante ligera)» | `gpt-4o-mini`, que es variante de GPT-4o, no de GPT-4 | 🟡 Redacción |

> **Con el criterio de esta sesión, siete de las diez se resuelven escribiendo.** «Manda el
> sistema» quiere decir que los nº 1, 6, 7, 9 y 10 se cierran ajustando el informe, no el
> código — están recogidos como correcciones concretas en `ANALISIS_MODELO_DATOS_TFG.md`
> §10.2. Quedan dos que **no** conviene resolver así:
>
> - **nº 8 (aviso de IA).** [397] promete algo razonable y barato de cumplir. O viaja en la
>   respuesta del chat, o el informe lo atribuye explícitamente a la interfaz; hoy no lo
>   dice ninguno de los dos, que es la única opción mala.
> - **Expiración de sesión** (§9.3): el informe pide 60 min y hay 12 h. Son cuentas de
>   menores en equipos posiblemente compartidos del centro. Aquí es más defendible bajar
>   la variable que relajar el requisito escrito.

**Sobre el nº 1 — es la decisión que hay que tomar antes de redactar.** No es un
descuido: meter una llamada a OpenAI de varios segundos dentro de la transacción del
submit ataría un worker de Octane justo en el pico real del sistema (todos los alumnos
entregan a la vez), que es lo que `ANALISIS_CONCURRENCIA.md` pide evitar. Las dos
salidas son legítimas:

- **(a) Diferirlo a la cola.** El submit responde al instante con las plantillas y un
  job regenera con IA después. Cumple el informe tal como está escrito y no toca la
  concurrencia. Cuesta trabajo: job, estado «recomendación en preparación» y el
  frontend enterándose de que llegó.
- **(b) Redactar el diseño real** — heurística inmediata + refinamiento IA bajo demanda.
  Cero código, y se defiende bien: respuesta instantánea, coste de API acotado y el
  alumno decide si quiere el análisis profundo. Hay que reescribir [122], [222], [255]
  y [736] para que digan eso.

**Sobre el nº 7 — bloquea a los nº 6 y 2.** Sin tema por ítem no hay diagnóstico por
tema, luego no hay «temas más recomendados» para el docente ([173]) ni recurso por
tema. `study_resources` tampoco tiene `subject_id`. Es una decisión de alcance: añadir
las columnas a `questions` (y llevarlas al ERD nuevo) o recortar la promesa en [171],
[222], [263] y [276].

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

### Sesión 30/06/2026 — Onboarding local + colección Postman

| # | Archivo(s) | Descripción | Estado |
|---|-----------|-------------|--------|
| F1 | `postman/` (nuevo) | **Colección Postman de toda la API** para pruebas manuales / con el front. `generate_postman_collection.php` lee `php artisan route:list --json` y genera `NeoEduCore.postman_collection.json` (**93 endpoints en 15 carpetas**, todas las rutas) + `NeoEduCore.postman_environment.json` (base_url, credenciales del seeder, variables de ids) + `README.md`. Login guarda `{{token}}` solo; los `GET` de listado autocapturan ids (`exam_id`, `subject_id`, ...). Como se genera desde las rutas reales, no se desincroniza: al cambiar `routes/api.php` se regenera con un comando. | ✅ |
| F2 | Nota de arranque local (`composer run dev`) | Aclarado el onboarding para nuevos equipos: `composer run dev` = `php artisan serve` + `php artisan queue:listen` vía `npx concurrently`, por lo que requiere **`npm install`** una vez (trae `concurrently`). Para solo probar el front basta `php artisan serve` (como antes); el worker solo hace falta para correos async/jobs. Alternativa sin Node: los dos comandos en dos terminales. | ✅ |
| F3 | `AiOutputValidator.php`, `AiTutorService.php`, `AiTutorController.php`, `config/sanctum.php`, `routes/console.php`, `.env.example` | **Endurecimiento del chatbot IA (auditoría de acceso y datos).** Se confirmó que el tutor NO es accesible sin auth (401 `auth:sanctum` → 403 `role:student` → 403 chequeo de perfil en controlador) y que el aislamiento de datos es **estructural**: el prompt solo lleva datos del propio estudiante y `AiChatSession` es `TenantScoped` + filtrado por `student_user_id` (sin IDOR ni fuga cross-tenant, incluso ante prompt injection). Ajustes aplicados: (A) filtro PII afinado — el patrón de teléfono `[0-9]{7,15}` marcaba números normales de matemáticas/fechas → reemplazado por patrones de teléfono/DNI reales (prefijo `+`, 3 grupos con separador, o ≥10 dígitos), sin bloquear resultados ni rangos de años; (B) `getDiagnosis` ahora también pasa por `AiOutputValidator` (coherencia con `chat`); (C) `subject_id` del chat se valida scoped por tenant (422 si no pertenece a la institución); (D) tokens Sanctum ahora **expiran** (`SANCTUM_TOKEN_EXPIRATION_MINUTES`, 12 h por defecto) + `sanctum:prune-expired` diario en el scheduler. **+11 tests** (`tests/Unit/AI/AiOutputValidatorTest.php`: PII no bloquea números de matemáticas/rangos de años pero sí email/teléfono/documento; `Level4_AiTutorFlowTest`: chat requiere auth 401, `subject_id` de otro tenant → 422, mismo tenant → 200). Suite: **159/159**. | ✅ |

> **Nota (resuelta 31/07/2026):** al generar la colección se detectó que `GroupController::addStudents()` y `removeStudents()` existían pero **no estaban enrutados**. Ya lo están (`POST`/`DELETE /api/groups/{group}/students`) — ver sesión 31/07/2026, G4.

### Sesión 08/08/2026 — Contraste con el informe: integridad, privacidad del tutor y personalización

> Origen: revisión del `.docx` contra el código, centrada en **las relaciones** y en
> **qué hace el tutor de verdad**. El análisis completo, en [§3.5](#35-las-relaciones-informe-vs-sistema-revisado-08082026)
> y [§3.6](#36-el-tutor-lo-que-hace-vs-lo-que-el-informe-dice). Suite: **305/305** (antes 290).
>
> **Criterio fijado en esta sesión: manda el sistema construido; el informe se ajusta a
> él.** El esquema `schema de la base de datos.sql` deja de ser «la referencia» y pasa a
> ser un boceto inicial. Eso cerró de un golpe las decisiones que llevaban meses abiertas
> por no querer desviarse de aquel documento (H8), y convierte todo lo que queda del
> modelo de datos en **correcciones al informe**, recogidas en `ANALISIS_MODELO_DATOS_TFG.md`
> §10 con cita y número de párrafo.

| # | Archivo(s) | Descripción | Estado |
|---|-----------|-------------|--------|
| H1 | migración `study_resources_created_by_set_null`, `01_schema.sql`, `CascadeIntegrityTest` | **`DELETE /api/users/{id}` daba 500 con docentes que hubieran subido recursos.** `study_resources.created_by` era la última FK a `users(id)` en NO ACTION, y `UserController::destroy()` hace `$user->delete()` sin manejar violaciones de FK: la excepción de PostgreSQL salía tal cual. Misma familia que los 500 de abril (G10) y **mismo punto ciego**: `test_deleting_a_teacher_keeps_their_exams` monta exámenes para el docente, pero no recursos. Pasa a SET NULL, coherente con las otras dos FK de autoría (`exams.created_by_teacher_id` y `calendar_events.created_by`): dar de baja a un docente no debe destruir el material que dejó al centro ni quedar bloqueado por él. El modelo del TFG **tampoco** lo cubría —declara la FK sin `ON DELETE`, igual que la implementación—, así que la comparación de G10 no lo marcó: ambas partes coincidían en el mismo hueco. **+1 test**, verificado que falla sin el arreglo | ✅ |
| H2 | `AiTutorService.php`, `AiOutputValidator.php`, `AiRecommendationService.php`, `AiTutorPrivacyTest.php` | **🔒 El nombre del alumno viajaba a OpenAI, y la lista blanca no cubría el chat.** (a) `buildSystemPrompt()` y `getDiagnosis()` incrustaban el `full_name` en el prompt: el nombre real de un menor salía hacia un tercero en **cada turno** de conversación, contra [173] («prohibirá datos personales») y [394] (anonimización, Ley 8968). `AiOutputValidator` no lo veía: filtra PII en la **salida**, nunca en la entrada. Ahora solo viaja contexto pedagógico —grado, estilo de aprendizaje, dominio por materia— y el saludo personalizado lo pone el frontend. (b) `isUrlAllowed()` solo se aplicaba al JSON estructurado de las recomendaciones; un enlace escrito en prosa por el tutor llegaba intacto a un niño de primaria. `sanitize()` sustituye ahora los enlaces fuera de `config/ai_resources.php` por `[enlace no permitido]` y conserva el resto de la explicación. (c) De paso: los cuatro textos de `regenerateForAttempt` **no pasaban por ningún validador** y se guardaban crudos en `ai_recommendations`, de donde van al alumno, al reporte del docente y al PDF. **+5 tests**, verificado que fallan sin el arreglo | ✅ |
| H3 | `AiRecommendationService.php` | **El recurso «personalizado» era el mismo para todo el mundo.** `StudyResource::orderBy('created_at','desc')->first()`: el último subido al centro, sin mirar al alumno. Un estudiante de 2.º que reprobaba recibía el material de 6.º si era el más reciente, mientras la Figura 10 y [263] prometen recursos personalizados. Ahora filtra por el rango de grado del recurso (`grade_min`/`grade_max`, columnas que existían y nadie usaba) y prefiere dificultad `basic`, que es lo que corresponde en la rama de bajo desempeño. **Limitación de modelo, no de código:** `study_resources` no tiene `subject_id`, así que no se puede acotar por materia — ver §3.6 nº 7. **+1 test** | ✅ |
| H4 | `AiTutorController.php`, `AiTutorService.php`, `ApiSpec.php`, `Level4_AiTutorFlowTest` | **`ai_chat_sessions.exam_id` era una relación muerta.** Estaba en la tabla, en `$fillable`, en la relación `exam()` y en el listado de sesiones, pero ninguna ruta la escribía: siempre NULL — y `ANALISIS_MODELO_DATOS_TFG.md` §3.4 la presenta al informe como relación real del sistema. Documentar una relación muerta en el ERD es peor que no tenerla. `POST /ai/tutor/chat` acepta ahora `exam_id`, válido si el alumno **rindió** el examen o si hoy lo tiene disponible: `scopeVisibleTo` a secas no sirve porque exige activo y en ventana, que es justo lo que deja de ser el examen recién entregado — el caso de uso principal. **+2 tests** | ✅ |
| H5 | `ReportController.php`, `TutorStrategiesTest`, `Level5_AnalyticsReportsTest` | **`tutor-usage` no era un reporte anónimo.** Devolvía `top_students_by_usage`: top 10 de alumnos **con nombre y propio** y cuántos mensajes escribió cada uno. [173] limita al personal docente a «métricas agregadas» y pide «reportes anónimos»; identificar a menores por su comportamiento de uso no es ninguna de las dos cosas. El ranking queda **solo para admin**, que responde por los datos del centro y lo necesita para detectar abuso o coste desbocado; al docente le llegan los agregados. Misma frontera que ya aplicaba `ReportStrategyService`: el docente accede al artefacto *pedagógico*, no al rastro de la conversación. La consulta ni se lanza si quien pregunta no es admin. **+2 tests** | ✅ |
| H6 | `ExamAttemptController.php`, `AiRecommendationsTest` | **El cupo de regeneraciones no contaba generaciones.** Era `ceil($totalFilas / 4)` sobre todas las recomendaciones del par (examen, materia), con dos errores: `generateFromAttempt` guarda 1 o 2 filas según el porcentaje —no 4—, así que la división no medía nada real; y sin corte temporal un **segundo intento** del mismo examen nacía con el cupo del primero gastado, pese a que la documentación decía «por intento». Ahora se cuentan los `generated_at` distintos desde la entrega de ese intento, que sí equivale a una generación, sin tocar el esquema (`ai_recommendations` no tiene `attempt_id`). **+1 test** | ✅ |
| H8 | migración `complete_tenant_fk_coherence`, `01_schema.sql`, `CascadeIntegrityTest` | **Coherencia de tenant cerrada.** De las 18 columnas `institution_id`, dos no tenían FK (`exam_attempts`, `student_progress`) y dos estaban en `NO ACTION` mientras el resto cascadeaba (`students`, `group_students`). Las cuatro venían de que la alineación de G10 se ciñó al boceto del TFG, que no las declaraba, y la duda quedó anotada como **«coherencia del modelo vs. fidelidad al documento de referencia»**. Al fijarse que **manda el sistema y el informe se ajusta**, esa tensión desaparece y gana la coherencia. Ahora las 18 tienen FK: 17 en `CASCADE` y `users` en `SET NULL` (deliberado). Las dos nuevas cierran el mismo agujero de aislamiento multi-tenant que se tapó en otras tres tablas; las dos endurecidas son hoy inalcanzables (no hay `DELETE /institutions`) y se arreglan justo por eso — el día que exista, `NO ACTION` habría dado un 500. Migración con detección previa de huérfanos y `down()` real. **+3 tests**, incluido el borrado completo de una institución | ✅ |
| H7 | `ESTADO_Y_PENDIENTES.md` | **Auditoría del propio documento.** Afirmaba 243 tests (eran 290), se fechaba el 31/07 con secciones del 03/08 y 05/08, daba por pendientes cosas ya resueltas (PDF del backend, medición del reporte de 1.000 estudiantes, prueba de carga) y se contradecía consigo mismo en los flujos del tutor —§3.2 los listaba como pendientes y la §5 como completados desde el 09/05—. Además hablaba de «React/Next.js» como frontend, repitiendo el error del informe que este mismo documento manda corregir. Detalle en §3.5, §3.6 y en las notas de corrección en línea | ✅ |

### Sesión 31/07/2026 — Catálogo de materias, drift de esquema y reasignación masiva

| # | Archivo(s) | Descripción | Estado |
|---|-----------|-------------|--------|
| G1 | `SubjectController.php`, `routes/api.php` | **Catálogo de materias reservado al admin.** `GET /subjects` gana filtros `search` (ILIKE con comodines escapados) y `per_page` (1..100), y devuelve `exams_count` por materia. Las **cuatro mutaciones** (POST/PUT/PATCH/DELETE) pasan de `admin,teacher` a **admin-only**: el catálogo define la oferta académica y `DELETE` cascadea a exámenes → preguntas → intentos → respuestas. Doble guarda: middleware `role:admin` + helper `denyIfNotAdmin()` en el controlador, para no depender solo del cableado de rutas. Los `GET` siguen abiertos a admin/teacher/student. **+10 tests** | ✅ |
| G2 | migración `add_unique_subject_name_per_institution`, `01_schema.sql`, `SubjectFactory.php` | **Nombre de materia único por institución.** Índice funcional `UNIQUE (institution_id, lower(btrim(name)))`: pueden coexistir "Matemática 1er grado" y "Matemática 2do grado", pero no dos "Matemática" ni variantes por mayúsculas/espacios. La migración **aborta con la lista de duplicados** si los hubiera, en vez de fallar a medias. El controlador valida en espejo (422 legible) y la BD es la garantía ante carreras. `SubjectFactory` pasa a componer nombres con contador estático (antes sorteaba entre 5 fijos y habría colisionado). Aplicada en Supabase: 206 materias, 0 duplicados | ✅ |
| G3 | migración Sanctum original, `fix_personal_access_tokens_tokenable_id_to_uuid`, `SchemaIntegrityTest.php` | **Drift real corregido: `personal_access_tokens.tokenable_id`.** Era `uuid` en prod pero las migraciones generaban `bigint` (Sanctum usa `morphs()`; los users tienen PK uuid) → **cualquier entorno recién migrado tenía la auth rota**. Arreglo en dos capas: la migración original pasa a `uuidMorphs('tokenable')` (entornos nuevos) + migración correctiva **idempotente** (entornos rezagados; no-op si ya es uuid — verificado en prod: 427 tokens intactos). Nuevo `SchemaIntegrityTest` como guardián. Tras esto, el único drift entre `01_schema.sql` y las migraciones es el `ENABLE ROW LEVEL SECURITY` que pone Supabase, que es esperado | ✅ |
| G4 | `routes/api.php`, `GroupController.php`, `GroupStudentsTest.php` | **Pendiente cerrado: membresía de grupo enrutada.** `POST`/`DELETE /api/groups/{group}/students` (admin+teacher). De paso se corrigió un **bug latente**: `addStudents()` no pasaba `institution_id` en su `upsert`, y la columna es `NOT NULL` sin default → habría reventado en la primera llamada real. No se detectó antes porque el método nunca estuvo enrutado. **+7 tests** | ✅ |
| G12 | `Question.php`, `QuestionOption.php`, `Concerns/RevelaRespuestas.php`, `QuestionController`, `ExamController`, `ExamAttemptController`, `AnswerLeakTest.php` | **🔒 Filtración de respuestas correctas al estudiante — corregida.** Detectada al inspeccionar respuestas durante la prueba de carga: `is_correct` y `correct_answer_text` viajaban al alumno por **tres rutas**: `GET /exams/{id}/questions`, `GET /exams/{id}` y —la más grave— `GET /exams/{id}/attempts/{id}` **con el intento en curso**, es decir mientras se estaba examinando. La protección se puso en los **modelos** (`$hidden`), no en cada controlador, para que sea por defecto: un endpoint nuevo que cargue preguntas nace seguro. Admin y docente las recuperan con el trait `RevelaRespuestas`. De paso se corrigió que `allow_review_after_submission` **se ignoraba por completo**: la corrección del intento se devolvía siempre, incluso con el examen en curso y aunque el docente hubiera desactivado la revisión — con `max_attempts > 1` eso filtraba las respuestas para el intento siguiente. Ahora la respuesta incluye `meta.review_shown`. `$hidden` no afecta al acceso a atributos, así que la corrección de exámenes sigue intacta (cubierto por test). **+8 tests** | ✅ |
| G11 | `LoadTestSeeder.php`, `k6/exam_peak.js`, `k6/baseline_decompose.js`, `ReportController.php`, `AppServiceProvider.php`, `ANALISIS_CONCURRENCIA.md` | **Prueba de carga real y dos bugs que destapó.** Ejecutada con k6 contra una base local desechable (nunca producción). **Validado:** el throughput escala ×6,6 de 1 a 8 workers, bajo sobrecarga degrada encolando con **cero errores 5xx**, y la BD no es el cuello (~142 queries/s). **Bug 1 (crítico):** `RateLimiter::for` en `bootstrap/app.php` reventaba con *"A facade root has not been set"* al servir por HTTP — **habría tumbado toda la aplicación en producción**; los tests no lo detectaban porque arrancan la app por otra vía. Movido a `AppServiceProvider::boot()`. **Bug 2:** N+1 en la exportación CSV — `with()` + `cursor()` es incompatible (`cursor()` ignora el eager loading), así que cada fila costaba 2 queries extra. Con `lazy()`: **3.790 ms → 1.068 ms** con 1.000 estudiantes, salida idéntica. Esto **verifica el requisito del informe** «reporte de 1000 estudiantes en <5 s». **+1 test** de regresión | ✅ |
| G10 | migración `align_fk_constraints_with_tfg_model`, `01_schema.sql`, `CascadeIntegrityTest.php` | **Alineación del modelo relacional con el informe del TFG.** Comparadas las FK de `schema de la base de datos.sql` (TFG) contra la BD real: **33 divergencias en 3 grupos.** (1) **4 relaciones apuntaban a la tabla equivocada** — `exam_attempts`, `student_progress`, `ai_recommendations` y `group_students` referenciaban `users(id)` en vez de `students(user_id)`; comprobado que permitía **crear un intento de examen a nombre de un docente**. (2) **26 `ON DELETE` distintos**, con consecuencia grave y verificada: con contenido real, `DELETE /subjects/{id}`, `/groups/{id}`, `/exams/{id}` y `/users/{id}` devolvían **HTTP 500** y no borraban nada, pese a que la documentación afirmaba que cascadeaban — los tests no lo veían porque borraban entidades vacías. (3) **3 FK de `institution_id` ausentes** (`ai_recommendations`, `question_options`, `student_answers`): la columna existía sin restricción. Migración con detección previa de huérfanos (0 en producción) y `down()` que restaura el estado anterior; probada up→down→up. Se conservan las 2 divergencias deliberadas (`exams.created_by_teacher_id` SET NULL, `exams.subject_id` CASCADE). **+8 tests** que montan la cadena completa antes de borrar. Aplicada en Supabase; 65 estudiantes / 206 materias / 73 usuarios intactos | ✅ |
| G9 | `AiTutorService.php`, `bootstrap/app.php`, `routes/api.php`, `.env`/`.env.example`, `AiTutorEfficiencyTest.php` | **Contención del tutor IA (eficiencia y protección del flujo de examen).** (a) Limitador **global** `ai-global` por institución (120/min) en las 3 rutas que llaman a OpenAI — los throttle previos eran por usuario y no acotaban el total. (b) Contexto del estudiante (perfil+progreso del system prompt) **cacheado 5 min**: se releía en cada turno → **7→3 queries por turno**, −54 % en una conversación de 20. (c) **Escritura incremental del JSONB**: `update(['messages' => $todos])` reenviaba la conversación entera cada turno; ahora solo viaja el delta y PostgreSQL concatena y recorta con `||` → **748 bytes constantes**, antes crecía sin parar. (d) **Dos agujeros de configuración**: `OPENAI_REQUEST_TIMEOUT` no estaba en `.env` (regía el default de 30 s, no los 15 que afirmaba el comentario del código) y **`CACHE_STORE=array`**, que con Octane hace que **ningún** rate limiter sea global (contador por worker). Ambos corregidos; `CACHE_STORE` **hay que fijarlo también en Coolify**. **+7 tests** | ✅ |
| G8 | `ExamGradingService.php`, `QueryBudgetTest.php`, `ANALISIS_CONCURRENCIA.md` | **Corrector de exámenes por lotes.** El bucle de `gradeAttempt` acumula respuestas y opciones en memoria y hace **dos INSERT por lotes** al final, en vez de `create()` + `syncWithPivotValues()` por pregunta. **De `20 + 3·N` a 22 queries constantes** (~80 → 22 en un examen de 20 preguntas); capacidad de entrega **de ~27/s a ~97/s**. Al salir de Eloquent hubo que asumir a mano lo que hacía por nosotros: `id` con `Str::orderedUuid()` (la tabla no tiene DEFAULT), `institution_id` explícito (no corre el hook de `TenantScoped`) y `correct_answer_snapshot` serializado a JSON (no se aplica el cast). Inserción en lotes de 500 por el límite de parámetros de PostgreSQL, y respuestas antes que opciones por la FK. El test pasa de exigir coste marginal acotado a exigir **coste plano**. Suite intacta: 219/219 | ✅ |
| G7 | `Level7_AcademicCycleTest.php`, `QueryBudgetTest.php`, `ANALISIS_CONCURRENCIA.md` | **Test de integración del ciclo + estimación de concurrencia.** Nivel 7: recorrido end-to-end de la promoción de fin de año (catálogo admin-only → grupos 2026/2027 → historial → repitentes → promovidos vía `from_group_id` → alumnos nuevos → replan → reseteo → verificación de que el corte aguanta el ciclo siguiente) + aislamiento multi-tenant de los tres endpoints masivos. **59 assertions en 2 tests.** `QueryBudgetTest` mide queries por endpoint y actúa de guardia anti-N+1: exige que el coste sea **plano** respecto al volumen (3 vs 40 alumnos, 9 vs 120 inscripciones). **Hallazgo:** todos los endpoints son planos **menos el submit de examen**, que cuesta `29 + 3·N` queries (bucle de `ExamGradingService` con `create()` + `syncWithPivotValues()` por pregunta). Es el cuello de botella de concurrencia — análisis completo y palancas en `ANALISIS_CONCURRENCIA.md` | ✅ |
| G6 | migración `add_reset_at_to_student_progress`, `StudentProgressService.php`, `StudentProgress.php`, `BulkReassignmentService/Controller`, `01_schema.sql` | **Reseteo de progreso para repitentes — cierra el ciclo académico.** `POST /api/bulk/reset-progress` (admin-only). El punto no trivial: `recalcFromAttempts` promedia **todos** los intentos enviados sin corte temporal, y se dispara en cada submit de examen y en cada revisión de respuesta — así que poner el progreso a 0 sin más se desharía solo. Se añade `student_progress.reset_at` como **marca de corte** que el recálculo respeta. Los intentos y respuestas **no se borran** (historial académico íntegro), solo dejan de contar para el dominio actual; los posteriores al corte cuentan con normalidad. `overall_average` se recomputa; `exams_completed_count` y `last_activity_at` no se tocan (decisión deliberada, documentada). **+11 tests**, incluidos los dos que importan: el reseteo sobrevive a un recálculo, y un intento posterior al corte sí computa | ✅ |
| G5 | `BulkReassignmentService.php`, `BulkReassignmentController.php`, `routes/api.php` | **Módulo de reasignación masiva** (promoción de fin de año y correcciones en bloque). `POST /api/bulk/reassign-group` y `POST /api/bulk/reassign-subjects`, **admin-only** + `throttle:10,1`. El lote se define por `student_user_ids` **o** `from_group_id` (mutuamente excluyentes). Mover **cierra** la membresía anterior con `left_at` (conserva historial), recuenta `student_count` de origen **y** destino, y sincroniza `students.grade/section/group_code` (flag `sync_student_fields`). Materias con `mode` = `replace`/`add`/`remove`, altas idempotentes vía `insertOrIgnore`. Todo transaccional; los ids desconocidos vuelven en `skipped` sin abortar el lote. **+18 tests** | ✅ |

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
  - ⚠️ **Estaba incompleto:** no cubría el texto libre del tutor, que es el canal por el
    que un enlace llega de verdad a un alumno. Cerrado el 08/08/2026 (H2)
- [x] **Métricas de uso del tutor** — `GET /reports/ai/tutor-usage` con total sesiones/mensajes, estudiantes únicos y top tipos de recomendación
  - El top 10 de estudiantes por uso es **solo admin** desde el 08/08/2026 (H5): con
    nombre y propio no era el «reporte anónimo» que promete [173]

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
- [x] ~~**Rutas de asignación de estudiantes a grupo sin enrutar (30/06/2026)**~~ → **Resuelto 31/07/2026 (G4).** Se optó por (a): `POST`/`DELETE /api/groups/{group}/students` enrutados para admin+teacher. Los métodos se mantienen porque cubren la asignación puntual a *un* grupo; el movimiento *entre* grupos lo cubre `/api/bulk/reassign-group`. De paso se corrigió el `institution_id` faltante en el `upsert`.

### 📌 Pendientes abiertos (anotados 03/08/2026)

> 📄 **Análisis completo en [`ANALISIS_MODELO_DATOS_TFG.md`](ANALISIS_MODELO_DATOS_TFG.md)** — comparación sistema↔informe, evidencia y lista accionable de correcciones al documento del TFG.

- [ ] **El informe del TFG describe un stack que no es el construido.** Afirma Node.js como capa de datos, Next.js en el frontend, autenticación JWT y despliegue en Render/Railway. La realidad es Laravel+Eloquent sin intermediario, React 19 + Vite, Sanctum y DigitalOcean/Coolify/Supabase. **4 correcciones críticas** — detalle y evidencia en `ANALISIS_MODELO_DATOS_TFG.md` §9.1.
- [x] ~~**El informe afirma exportación a PDF, que no existe**~~ → **Backend resuelto el 05/08/2026.** Los endpoints `summary` entregan las series de barras, líneas y pastel, y `history.csv` la exportación individual; el PDF lo compone el frontend (decisión de arquitectura en `ANALISIS_MODELO_DATOS_TFG.md` §9.8.1, con las dos alternativas implementadas y comparadas). **Queda fuera del backend:** que el frontend lo arme. Hasta entonces [215] sigue siendo parcialmente falsa a nivel de sistema.
- [x] ~~**El informe no tiene diagrama de modelo de datos**~~ → **Dibujados el 08/08/2026** en [`DIAGRAMAS.md`](DIAGRAMAS.md): ERD, aislamiento multi-tenant, clases, arquitectura, y los flujos de autenticación, examen, tutor IA, casos de uso y ciclo académico. Los 11 diagramas se validaron con el parser de Mermaid y el ERD se cruzó contra `01_schema.sql` (19/19 tablas, todos los valores de enum). **Queda incorporarlos al `.docx`**: la tabla final de `DIAGRAMAS.md` dice qué figura del informe sustituye cada uno.
- [ ] **Requisitos de rendimiento: dos de tres medidos.** ✅ «≤2 s bajo carga normal» (p95 = 1,19 s con 8 workers) y ✅ «reporte de 1000 estudiantes en <5 s» (3.790 → 1.068 ms tras corregir el N+1) quedaron medidos el 03/08/2026 (G11). 🟡 Sigue abierto **«200 concurrentes»**: está validado que el throughput escala ×6,6 de 1 a 8 workers y que satura sin errores 5xx, pero no la cifra exacta sobre el despliegue real con Octane. Ver §9.5.
- [x] ~~**`exam_attempts.institution_id` y `student_progress.institution_id` siguen sin FK**~~ → **Resuelto el 08/08/2026 (H8).** Se dejaron fuera de G10 por no desviarse del boceto del TFG; al fijar que manda el sistema, esa razón deja de valer. Ver `ANALISIS_MODELO_DATOS_TFG.md` §7.2.
- [ ] **Los borrados ahora son realmente destructivos.** Antes `DELETE /subjects|/groups|/exams|/users` fallaban con 500 cuando había contenido, lo que protegía por accidente. Ahora funcionan y cascadean: borrar una materia elimina sus exámenes **y todos los resultados de los alumnos**. El frontend debería pedir confirmación explícita. Ver `ANALISIS_MODELO_DATOS_TFG.md` §7.3.

### 📌 Pendientes abiertos (anotados 08/08/2026)

> 📄 Contexto y evidencia en [§3.5](#35-las-relaciones-informe-vs-sistema-revisado-08082026) y [§3.6](#36-el-tutor-lo-que-hace-vs-lo-que-el-informe-dice).

- [ ] 🔴 **Decidir qué dice el informe sobre las recomendaciones post-examen.** Hoy el submit las genera con plantillas, no con IA; el informe afirma lo contrario en cuatro pasajes ([122], [222], [255], [736]). Opciones: (a) diferir la generación IA a la cola, o (b) redactar el diseño real. **Es la decisión más expuesta del TFG** y condiciona la redacción del capítulo del tutor.
- [ ] 🔴 **`questions` no tiene tema, indicador ni dificultad**, y `study_resources` no tiene `subject_id`. Sin esos metadatos no hay diagnóstico por tema, ni «temas más recomendados» para el docente ([173]), ni recurso por materia. Bloquea también el entregable «banco de 60 ítems con metadatos» de [171]. Decisión de alcance: añadir las columnas o recortar la promesa en [171], [222], [263] y [276].
- [ ] 🟡 **El aviso de que la respuesta viene de una IA no está en el contrato.** [397] promete que el sistema lo informa «cada vez que el tutor intervenga»; la respuesta del chat es `{session_id, reply, message_count}`. O viaja en la respuesta, o queda como responsabilidad escrita del frontend.
- [x] ~~**`students.institution_id` y `group_students.institution_id` en NO ACTION**~~ → **Resuelto (H8).** Ver también §7.2 de `ANALISIS_MODELO_DATOS_TFG.md`.
- [x] ~~**Corregir las cifras del modelo de datos en `ANALISIS_MODELO_DATOS_TFG.md` §9.2**~~ → **Corregidas.** Son **47 FK** y 19 tablas de dominio (15 entidades + 4 pivotes), y el estado final está ahora en §1.1 de aquel documento.
- [x] ~~**Aplicar a producción las dos migraciones del 08/08 y regenerar `01_schema.sql`**~~ → **Hecho el 08/08/2026.** `migrate --force` + `schema:dump-sql` contra Supabase. Verificado en `pg_constraint`: 47 FK, las 5 tocadas correctas, y de las 18 columnas `institution_id` la única que no cascadea es `users` (`SET NULL`, deliberado). Datos idénticos antes y después (73 usuarios, 65 estudiantes, 206 materias, 3 intentos). **`ENABLE ROW LEVEL SECURITY` conservado en las 24 tablas** — era el riesgo de regenerar el artefacto. El dump solo reordenó dos bloques respecto a la edición a mano (pg_dump ordena por nombre de constraint); el contenido coincidía.
- [ ] 📄 **Llevar al `.docx` las contradicciones de `ANALISIS_MODELO_DATOS_TFG.md` §10.** Ocho internas (§10.1) y once contra el sistema (§10.2). ⚠️ **Los números de párrafo de la §9 están desfasados**: el `.docx` se editó después. Equivalencias en §10.3, y la recomendación es buscar por texto, no por índice.

### 📌 Pendientes abiertos (anotados 31/07/2026)

- [x] ~~**Cuello de botella de concurrencia: el submit de examen cuesta `20 + 3·N` queries**~~ → **Resuelto 31/07/2026 (G8).** `ExamGradingService::gradeAttempt` acumula las filas y hace dos INSERT por lotes: **22 queries constantes** (antes ~80 para 20 preguntas). Capacidad de entrega **de ~27/s a ~97/s**; el escenario de saturación desaparece del flujo de examen. `QueryBudgetTest` exige ahora coste plano. Nota: la mejora real fue ≈3,5×, no el ≈8× estimado, porque el coste fijo del endpoint (~20 queries) es ahora el que manda → nueva palanca en `ANALISIS_CONCURRENCIA.md` §5.5.
- [ ] **Coste fijo del submit: ~20 de las 22 queries son overhead**, no corrección (auth, tenant, validación, `recalcFromAttempts`, recomendaciones). Bajarlo a ~12 daría ~170 entregas/s. No urgente. Perfilarlo antes de tocar; buena parte podría ser `recalcFromAttempts`, diferible a la cola. Ver §5.5.
- [x] ~~**El tutor IA puede dejar sin workers al resto del sistema**~~ → **Resuelto 03/08/2026 (G9).** Limitador global `ai-global` por institución en las 3 rutas de OpenAI + contexto cacheado (7→3 queries/turno) + escritura incremental del JSONB (bytes constantes) + timeout acotado a 15 s. Ver `ANALISIS_CONCURRENCIA.md` §5.3.
- [x] ~~**Validar empíricamente el modelo de concurrencia**~~ → **Ejecutado 03/08/2026 (G11).** Prueba de carga con k6 contra base local desechable. Validado: coste por petición plano, throughput escala ×6,6 de 1 a 8 workers, saturación limpia sin errores 5xx, BD no es el cuello. Resultados en `ANALISIS_CONCURRENCIA.md` §6.
- [ ] **Medir el RTT real desde el contenedor desplegado.** Es lo único que no se puede medir desde desarrollo (desde el portátil son ~152 ms) y es el parámetro que domina todo el modelo. `psql "$DATABASE_URL" -c '\timing on' -c 'SELECT 1;'` desde producción. Ver `ANALISIS_CONCURRENCIA.md` §6.5.

- [ ] **Detección de drift esquema↔migraciones no automatizada.** El drift de `tokenable_id` (G3) vivió meses sin detectarse porque `SchemaIntegrityTest` corre sobre `01_schema.sql`, no contra las migraciones. El chequeo real hay que hacerlo a mano: BD limpia → `php artisan migrate` apuntando ahí → `php artisan schema:dump-sql --output=<tmp>` → `git diff --no-index` contra el artefacto. Si aparece algo más que el `ENABLE ROW LEVEL SECURITY` y la línea de versión de `pg_dump`, hay drift. **Pendiente:** empaquetarlo como comando `schema:check-drift` para poder correrlo en CI. **No regenerar `01_schema.sql` a ciegas:** se perdería el RLS que pone Supabase.
- [ ] **`ENABLE ROW LEVEL SECURITY` fuera de las migraciones.** Lo aplica Supabase sobre las 24 tablas y no está en el historial de migraciones, así que un despliegue en un PostgreSQL que no sea Supabase no lo tendrá. Hoy no es un problema (el rol de la app es dueño de las tablas y las bypassa, y el aislamiento real lo da `TenantScoped`), pero conviene decidir si se formaliza en una migración.

---

> **Backend prácticamente completo** — módulos, bugs, seguridad, optimizaciones, brechas TFG, tests de integración y comportamiento de eliminación implementados. Quedan los pendientes abiertos de arriba (no bloqueantes).
> Fuera del código: frontend (**React 19 + Vite + TypeScript**, sin Next.js), banco de ítems (60+ preguntas), piloto con usuarios, documento académico.
>
> Lo que **sí** puede bloquear la defensa no es técnico: lo que el informe dice que hace
> el tutor ([§3.6](#36-el-tutor-lo-que-hace-vs-lo-que-el-informe-dice)) y las
> contradicciones del documento consigo mismo, recogidas por escrito en
> `ANALISIS_MODELO_DATOS_TFG.md` §10 para que el equipo entre a corregirlas.

---

## 6. Referencia de endpoints existentes

### Públicos (sin auth)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/ping` | Health check |
| POST | `/api/auth/login` | Login |
| POST | `/api/password/forgot` | Solicitar reset |
| POST | `/api/password/verify` | Verificar token de reset |
| POST | `/api/password/reset` | Resetear contraseña |

### Autenticados — cualquier rol
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/auth/me` | Usuario autenticado |
| POST | `/api/auth/logout` | Revocar el token actual |
| POST | `/api/password/change` | Cambiar la propia contraseña |

> También existe `GET /api/documentation` (interfaz Swagger servida por L5-Swagger
> sobre el `api-docs.json` que genera `php artisan openapi:generate`). No es un
> endpoint de la API.

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
| POST | `/api/ai/tutor/chat` | Chat con tutor IA — contexto opcional `subject_id` y `exam_id` |
| GET | `/api/ai/tutor/sessions` | Mis sesiones del tutor |
| PATCH | `/api/ai/tutor/sessions/{id}/end` | Finalizar sesión del tutor |

### Autenticados — Admin, Profesor y Estudiante (lectura compartida)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/exams` | Lista exámenes |
| GET | `/api/exams/{exam}` | Ver examen |
| GET | `/api/exams/{exam}/questions` | Ver preguntas |
| GET | `/api/subjects` | Lista materias — filtros `search` (parcial, ignora mayúsculas) y `per_page` (1..100); incluye `exams_count` |
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
| GET/POST/PUT/DELETE | `/api/groups` | CRUD grupos (`apiResource`) |
| POST | `/api/groups/{group}/students` | Alta de estudiantes en el grupo (body: `student_user_ids[]`). Idempotente; reabre membresías cerradas |
| DELETE | `/api/groups/{group}/students` | Baja **lógica** del grupo (body: `student_user_ids[]`) — marca `left_at`, conserva historial |
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
| GET | `/api/reports/exams/{exam}/summary` | Agregados del examen para gráficos |
| GET | `/api/reports/students/{id}/history` | Historial estudiante |
| GET | `/api/reports/students/{id}/history.csv` | CSV del historial |
| GET | `/api/reports/students/{id}/summary` | Agregados del estudiante para gráficos |
| GET | `/api/reports/students/{id}/strategies` | Estrategias del tutor (acotado a exámenes propios) |
| GET | `/api/reports/ai/tutor-usage` | Métricas de uso del tutor — el top nominal de alumnos **solo lo ve el admin** |
| GET | `/api/system/config` | Configuración de la institución |
| GET | `/api/analytics/institution` | Estadísticas institucionales |
| GET | `/api/analytics/subjects` | Rendimiento por materia |
| GET | `/api/analytics/students/{id}` | Detalle analítico de estudiante |

### Autenticados — Solo Estudiante (adicionales)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/students/me/subjects` | Mis materias inscritas |
| GET | `/api/ai/tutor/diagnosis` | Diagnóstico IA de mi progreso |
| GET | `/api/reports/students/me/strategies` | Mis estrategias del tutor (sin el chat) |

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
| POST | `/api/subjects` | Crear materia — nombre único por institución (ignora mayúsculas/espacios) |
| PUT/PATCH | `/api/subjects/{subject}` | Renombrar materia — misma regla de unicidad |
| DELETE | `/api/subjects/{subject}` | Eliminar materia — **cascadea** a exámenes → preguntas → intentos → respuestas |
| POST | `/api/bulk/reassign-group` | Reasignación masiva de grupo (`throttle:10,1`) |
| POST | `/api/bulk/reassign-subjects` | Reasignación masiva de materias (`throttle:10,1`) |
| POST | `/api/bulk/reset-progress` | Reseteo de progreso para repitentes (`throttle:10,1`) |
| PUT | `/api/system/config` | Editar configuración (incl. `passing_percentage`) |

#### Reasignación masiva — contrato

Ambos endpoints aceptan el lote por **`student_user_ids`** (lista de uuid) **o** por **`from_group_id`** (todos los activos de ese grupo). Son mutuamente excluyentes: mandar los dos da 422.

`POST /api/bulk/reassign-group`

```jsonc
{
  "student_user_ids": ["uuid", "..."],   // o "from_group_id": "uuid"
  "to_group_id": "uuid",                 // requerido
  "sync_student_fields": true            // opcional (default true)
}
```

- Cierra con `left_at` las membresías activas en otros grupos (conserva historial).
- Da de alta en el destino; si el estudiante ya estuvo ahí y se fue, reabre la fila con `joined_at` nuevo.
- Los que **ya están activos** en el destino no se tocan (salen como `already_in_group`, no como `moved`).
- Recalcula `student_count` de los grupos de origen **y** del destino.
- Con `sync_student_fields` sincroniza `students.grade`, `section` y `group_code` con el grupo destino.

`POST /api/bulk/reassign-subjects`

```jsonc
{
  "from_group_id": "uuid",               // o "student_user_ids": ["uuid", ...]
  "subject_ids": ["uuid", "..."],
  "mode": "replace"                      // replace | add | remove
}
```

| `mode` | Efecto |
|--------|--------|
| `replace` | El estudiante queda **exactamente** con las materias indicadas. Con lista vacía queda sin materias (es válido y deliberado) |
| `add` | Añade las indicadas sin quitar nada. Idempotente: repetir la llamada no duplica ni pisa el `enrolled_at` original |
| `remove` | Desinscribe solo las indicadas |

`add` y `remove` exigen al menos una materia (422 si va vacío); solo `replace` admite lista vacía.

`POST /api/bulk/reset-progress` — **reseteo de progreso (repitentes)**

```jsonc
{
  "from_group_id": "uuid",               // o "student_user_ids": ["uuid", ...]
  "subject_ids": ["uuid", "..."]         // opcional; vacío = todas sus materias
}
```

Pone `mastery_percentage` a 0 **y marca `student_progress.reset_at`**. Esa marca es lo que hace el reseteo duradero: `StudentProgressService::recalcFromAttempts` ignora los intentos anteriores al corte, así que el próximo examen enviado (o la próxima revisión de respuesta) no restaura la nota del año pasado. Sin ella, el reseteo sería puramente cosmético.

- **Los intentos y respuestas NO se borran**: el historial académico se conserva íntegro, solo deja de contar para el dominio actual.
- `students.overall_average` se recomputa en el acto (deriva del progreso).
- **No** se toca `exams_completed_count` (es un total histórico: el estudiante sí rindió esos exámenes) ni `last_activity_at` (esto es una acción del admin, no actividad del estudiante).
- Los intentos **posteriores** al corte vuelven a contar con normalidad.

**Respuesta** (los tres): resumen con `requested`, `skipped` y los contadores de la operación. Los ids que no correspondan a un estudiante de la institución **no abortan el lote**: vuelven en `skipped`. Lo que sí corta es un grupo o materia de otra institución (404 / 422). Todo corre en una transacción: se aplica el lote entero o nada.

#### Receta: promoción de fin de año (con repitentes y alumnos nuevos)

Punto de partida: **quedarse en el mismo grado no es quedarse en el mismo grupo**. Los grupos llevan `year`, así que "7-A 2026" y "7-A 2027" son filas distintas. Un repitente **también se reasigna**: de 7A‑2026 a 7A‑2027. Si no se le mueve, su membresía sigue apuntando al grupo del año pasado y contamina el `student_count` de un grupo que ya no existe operativamente.

El orden importa, y aprovechándolo no hace falta ningún parámetro de exclusión:

1. **Crear los grupos del año nuevo** (7A‑2027, 8A‑2027, …).
2. **Mover primero a los repitentes**, por lista explícita, al grupo de su mismo grado del año nuevo:
   ```jsonc
   { "student_user_ids": ["<repitentes>"], "to_group_id": "<7A-2027>" }
   ```
3. **Mover al resto con `from_group_id`**. Como el paso 2 ya cerró la membresía de los repitentes en 7A‑2026, `from_group_id` **solo devuelve a los que quedan**, es decir los promovidos:
   ```jsonc
   { "from_group_id": "<7A-2026>", "to_group_id": "<8A-2027>" }
   ```
4. **Alumnos nuevos de matrícula:** primero existir (`POST /api/register` o `POST /api/students/bulk-upload`, ambos admin-only), después asignarlos con `POST /api/groups/{group}/students` o con `reassign-group` por lista.
5. **Plan de materias por grupo**, con `mode: "replace"`:
   ```jsonc
   { "from_group_id": "<7A-2027>", "subject_ids": ["<plan de 7º>"], "mode": "replace" }
   ```

Notas del paso 5 y sus efectos sobre repitentes:

- `replace` **no reinicia** las inscripciones que ya existían: borra solo lo que sobra y las altas usan `insertOrIgnore`, así que una materia que el repitente ya cursaba conserva su `enrolled_at` original.
- `reassign-subjects` **no toca el progreso**. Para que el repitente arranque de cero está el paso 6.

6. **Resetear el progreso de los repitentes** (solo ellos, no los promovidos):
   ```jsonc
   { "student_user_ids": ["<repitentes>"] }   // o "from_group_id": "<7A-2027>"
   ```
   `POST /api/bulk/reset-progress`. Sin `subject_ids` resetea todas sus materias; con `subject_ids` solo las indicadas (útil si arrastra únicamente algunas).

> **Mejora opcional no implementada:** un `exclude_student_user_ids` en `reassign-group` permitiría hacer los pasos 2 y 3 sin depender del orden. Con el orden de arriba no es necesario.

---

*Sección de reasignación masiva, redactada el 31/07/2026. La fecha del documento
completo está en la cabecera.*

---

## 7. Límites de peticiones (07/08/2026)

Definidos en `config/rate_limits.php`, ajustables por entorno. Los limitadores con
nombre se registran en `AppServiceProvider::registrarLimitadores()`.

| Ámbito | Límite | Clave |
|---|---|---|
| **Toda la API** (grupo `api`) | 120/min | usuario autenticado, o IP si no lo hay |
| **`POST /auth/login`** | 5/min **y** 60/min | correo+IP **y** IP |
| Contraseñas (`forgot`, `reset`, `change`) | 5/min | IP |
| `password/verify` | 10/min | IP |
| Tutor IA (chat) | 30/min + **`ai-global` 120/min** | usuario + **institución** |
| `ai/generate` | 20/min | usuario |
| Carga masiva | 3/min | usuario |
| Operaciones en lote | 10/min | usuario |

**El login no tenía ningún tope hasta el 07/08/2026.** Con `BCRYPT_ROUNDS=10` cada
intento cuesta ~100 ms de CPU, así que servía para fuerza bruta *y* para agotar los
workers. La clave compuesta **correo+IP** es deliberada: solo por correo, un tercero
podría dejar fuera a un alumno agotándole el cupo antes de un examen; solo por IP,
se bloquearía a un aula entera tras el mismo NAT — que es el pico real del sistema.
Cubierto por `tests/Feature/Auth/LoginThrottleTest.php`, incluido un caso de 30
alumnos entrando desde la misma IP.

⚠️ **Y todo depende de `TRUSTED_PROXIES`.** En producción la app va detrás de
Traefik: sin declarar proxies de confianza, `$request->ip()` devuelve la IP del
proxy y **todos los límites por IP agrupan a los usuarios en un único cubo** — el
tope de 60/min pensado para un aula tras un NAT se aplicaría a todo el sistema a
la vez, y se autobloquearía sin que nadie lo atacara. Configurado en
`config/trusted_proxies.php` (rangos privados por defecto, que no son
falsificables desde internet). Cubierto por `tests/Feature/Auth/TrustedProxiesTest.php`.

**Defensa en profundidad, más allá de los límites:**

| Medida | Dónde |
|---|---|
| `statement_timeout` 15 s en peticiones HTTP | `config/database.php` + `AppServiceProvider`; **no** en consola, para no cortar migraciones |
| Reciclado de workers cada 500 peticiones | `Dockerfile` (`octane:start --max-requests=500`) |
| Tamaño máximo de subida | 5 MB / 5.000 filas en carga masiva |

**Lo que NO cubre la aplicación:** un DDoS volumétrico no se para desde Laravel.
Hace falta una capa de red delante (Cloudflare), hoy **no desplegada** — ver
`docs/DEPLOY_COOLIFY.md` §9. Conviene decirlo así en el informe: el requisito no
funcional de disponibilidad del 99 % no se sostiene solo con la aplicación.

⚠️ **Todo esto depende de `CACHE_STORE`.** Los contadores viven en la caché: con
`array` y Octane cada worker lleva el suyo y ningún límite es global — con ~40
workers, un 5/min se convierte en 200/min. Debe ser `database`, `file` o `redis`.
`AppServiceProvider` escribe un `Log::warning` al arrancar si detecta `array` fuera
de tests.

---

## 8. Ciclo de vida de la cuenta (07/08/2026)

**Una cuenta creada por carga masiva nace `inactive` y la activa su dueño** al
definir la contraseña desde el enlace del correo. Es el único punto donde una
cuenta pasa a `active` por acción del usuario.

| Vía de alta | `status` inicial | Motivo |
|---|---|---|
| Carga masiva CSV/XLSX | **`inactive`** | La contraseña es aleatoria: nadie puede entrar hasta que su dueño la defina |
| `POST /api/register` (admin) | `active` | El admin escribe la contraseña y la entrega en mano; **no se envía correo**, así que crearla inactiva la dejaría inservible |

Flujo completo del alta:

```
Admin sube CSV → User(status=inactive, contraseña aleatoria) + Student
   ↓ PasswordSetupService: token hasheado en password_reset_tokens
   ↓ PasswordSetupMail encolado → lo envía el worker
Usuario pulsa el enlace → GET /password/reset/{token}?email=…  (formulario Blade)
   ↓ POST /api/password/reset
   ↓ guarda password_hash · status pasa a active · consume el token · revoca sesiones
Ya puede entrar → POST /api/auth/login  (exige status === active)
```

Reglas que sostienen el flujo:

- **`/password/forgot` responde a cuentas `active` e `inactive`, nunca a `suspended`.** Sin esto, quien perdiera el correo de alta quedaba bloqueado para siempre. Una cuenta suspendida la bloqueó un administrador a propósito y no debe tener vía de vuelta.
- **El correo se elige según el estado:** `inactive` recibe `PasswordSetupMail` («activá tu cuenta»); el resto, `PasswordResetMail`. A quien nunca tuvo contraseña no se le habla de recuperarla.
- **Solo se activa desde `inactive`.** Un reset sobre una cuenta `suspended` cambia la contraseña pero **no la reactiva**.
- La columna `users.status` pasó a `DEFAULT 'inactive'` (migración `2026_08_07_000001`) para que la base no contradiga la regla. **No modifica filas existentes.**

Sigue pendiente de decisión: `PATCH /users/{id}/reset-password` (un admin fija la
contraseña de otro) **no activa** la cuenta. Es coherente con «la activa su
dueño», pero deja una trampa de soporte: el admin cambia la contraseña, el
usuario sigue sin poder entrar y no es evidente por qué. Se resuelve con
`PATCH /users/{id}/status`.

---

## 9. Entregables del TFG fuera del código

> Rescatado de `ANALISIS_BRECHAS_TFG.md` (17/04–09/05/2026) al eliminarlo el 05/08/2026: el
> resto de aquel documento había quedado obsoleto —hablaba de 142 tests, de «JWT» y de que el
> proyecto «carece completamente de frontend»— y su función de comparar sistema contra informe
> la cubre hoy `ANALISIS_MODELO_DATOS_TFG.md` §9. Esto es lo único que no estaba recogido en
> ningún otro sitio. **Cada punto se reverificó contra el código el 05/08/2026**; los que ya
> estaban resueltos (validación MIME del bulk-upload, política de contraseñas en `resetPassword`,
> RBAC, whitelist de recursos IA, headers de seguridad, N+1, índices) no se han traído.

### 9.1 Entregables no-código exigidos por el TFG

| Entregable | Prioridad | Nota |
|---|---|---|
| Mockups / prototipo visual (Figma) — Sprint 1 | ALTA | Sin prototipo entregado |
| **Banco de ítems**: mínimo 60 preguntas reales con metadatos (tema, indicador, dificultad) | ALTA | Los seeders traen muy pocas |
| Acta del taller de co-diseño con docentes | ALTA | Entregable de Fase 1 |
| Piloto con usuarios reales (docentes y estudiantes) | ALTA | Fase de validación; condiciona los cap. 7 y 8 del informe |
| Rúbricas para preguntas abiertas | MEDIA | Fase 2 |
| Mini-guía para creación de nuevos ítems | MEDIA | Fase 2 |
| Manual de usuario básico en línea | MEDIA | Requisito no funcional de usabilidad |
| Cronograma / bitácora del proyecto | MEDIA | Capítulo 11 del informe |
| Anexo 1: encuestas a docentes y estudiantes | — | Diseñar y aplicar |
| Anexo 2: guía de entrevistas semi-estructuradas | — | Diseñar y aplicar |

### 9.2 Capítulos del informe pendientes

Además de las 10 correcciones de `ANALISIS_MODELO_DATOS_TFG.md` §9, que son sobre lo ya escrito:

| Capítulo | Estado | Acción |
|---|---|---|
| 3 · Metodología | ❌ | Describir Scrum e instrumentos de recolección |
| 5 · Conclusiones y recomendaciones | ❌ | Al cerrar la implementación |
| 7 · Validación y resultados del piloto | ❌ | Requiere el piloto |
| 8 · Discusión de resultados | ❌ | Post-piloto |
| 9 · Aspectos éticos, legales y de privacidad | ❌ | **Datos de menores**: relevante para el tutor IA y las recomendaciones |
| 10 · Trabajo futuro y escalabilidad | ❌ | Roadmap post-TFG |
| 11 · Gestión del proyecto | ❌ | Sprints, hitos, riesgos |
| 1 · Introducción · 2 · Marco teórico · 4 · Resultados · 6 · Implementación | ⚠️ Parcial | Completar y actualizar diagramas |

### 9.3 Brechas técnicas todavía abiertas

Reverificadas contra el código el 05/08/2026:

| Brecha | Gravedad | Evidencia |
|---|---|---|
| **Expiración de sesión: el informe exige 60 min, el sistema tiene 12 h** | ALTA | `config/sanctum.php`: `'expiration' => env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 60 * 12)`. Es una discrepancia con el requisito no funcional de seguridad del informe: o se ajusta la variable, o se corrige el informe |
| **Log de incidentes del tutor IA** | ALTA | No existe tabla ni servicio: los bloqueos por PII o por enlace fuera de la lista blanca solo dejan `Log::warning` en `AiTutorService` y `AiRecommendationService`. El informe [173] promete que «registrará incidencias» y fija como criterios de éxito «cero incidentes de PII» y «más del 75 % de mensajes que superen validación» — **el segundo no es calculable**: no se persiste ni el total validado ni el bloqueado. O se añade el contador, o se retira el criterio del informe |
| **Backups cifrados de la BD** | ALTA | Sin script ni documentación. Requisito no funcional de seguridad |
| **Documentación OpenAPI** | ~~ALTA~~ ✅ | Resuelto el 05/08/2026: `php artisan openapi:generate` produce los **103 endpoints** desde las rutas reales. Antes había 6 anotados a mano. No se edita a mano y no se desincroniza |
| **Medición de cobertura ≥70%** | ALTA | El TFG la exige; no hay reporte generado. `php artisan test --coverage --min=70` (requiere Xdebug o PCOV) |
| **HTTPS/TLS documentado** | MEDIA | Lo resuelve Coolify, pero debe quedar escrito en `DEPLOY_COOLIFY.md` |
| **Monitoreo y alertas de caída** | MEDIA | Requisito no funcional de disponibilidad; sin Sentry ni equivalente |
