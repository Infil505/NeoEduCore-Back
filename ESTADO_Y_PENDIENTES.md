# NeoEduCore — Estado del proyecto y pendientes
**Última actualización:** 21 de abril de 2026  
**Rama activa:** Darwin  
**Tests:** 82 pasando / 0 fallando

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
- OpenAI GPT-4o-mini — recomendaciones IA (solo en `regenerate`)
- SMTP — correo de recuperación de contraseña

**Multi-tenancy:** cada request autenticado inyecta `institution_id` via `SetTenantFromAuth`, activando el scope `TenantScoped` en todos los modelos.

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
- CRUD completo: list, show, update, set-status, reset-password
- Ruta: `GET/PUT/PATCH /api/users/{id}`

---

### ✅ Instituciones
- CRUD completo
- Ruta: `/api/institutions`

---

### ✅ Materias (Subjects)
- CRUD completo
- Ruta: `/api/subjects`

---

### ✅ Grupos
- CRUD completo
- Sin `withTimestamps` en pivot (bug corregido)
- Ruta: `/api/groups`

---

### ✅ Estudiantes
- List, show, update, me, set-status
- PK = `user_id` (no `id`)
- Ruta: `/api/students`

---

### ✅ Exámenes — Creación y gestión
- CRUD completo con máquina de estados: `draft → published → active → completed`
- Asignación a grupos via `exam_targets`
- Campos guardados: `randomize_questions`, `duration_minutes`, `max_attempts`, `available_from/until`
- Ruta: `/api/exams`

---

### ✅ Preguntas
- CRUD con validación por tipo:
  - `multiple_choice` → exactamente 4 opciones, 1 correcta
  - `true_false` → exactamente 2 opciones, 1 correcta
  - `short_answer` → requiere `correct_answer_text`, sin opciones
- Ruta: `GET /api/exams/{exam}/questions`, `POST/PUT/DELETE /api/questions/{id}`

---

### ✅ Intento de examen (flujo principal)
- **Start** `POST /api/exams/{exam}/attempts/start`
  - Valida: status=active, ventana de disponibilidad, max_attempts
  - Crea registro con `started_at`, calcula `max_score` sumando puntos de preguntas
- **Submit** `POST /api/exams/{exam}/attempts/{attempt}/submit`
  - Valida tipo de respuesta por tipo de pregunta
  - Auto-califica MC y TF
  - Deja SA como `needs_review`
  - Calcula `score` y `max_score`
  - Actualiza progreso por materia
  - Genera recomendaciones IA (plantillas estáticas)
- **Show** `GET /api/exams/{exam}/attempts/{attempt}`
- **List** `GET /api/exams/{exam}/attempts`

---

### ✅ Respuestas de estudiantes
- **List** `GET /api/exam-attempts/{attempt}/answers`
- **Review** `PATCH /api/student-answers/{answer}/review` — revisión manual de SA

---

### ⚠️ Recomendaciones IA (parcial)
- **List** `GET /api/ai-recommendations`
- **Me** `GET /api/ai-recommendations/me`
- **Show** `GET /api/ai-recommendations/{id}`
- **Generate (texto libre)** `POST /api/ai/generate` — llama OpenAI directamente
- **Regenerate post-examen** `POST /api/exam-attempts/{attempt}/recommendations/regenerate`
  - **Este sí llama GPT-4o-mini**
  - Límite: 1 generación + 3 regeneraciones por intento
  - Fallback a plantillas estáticas si OpenAI falla

> Lo que NO existe: sesión conversacional, historial de chat, estilos de aprendizaje,
> modos de interacción (practicar / estudiar / preguntar).

---

### ✅ Progreso del estudiante
- Se actualiza automáticamente al enviar examen (`StudentProgressService`)
- Calcula `mastery_percentage` como promedio de porcentajes por materia
- **List** `GET /api/student-progress`
- **Me** `GET /api/student-progress/me`
- **Upsert manual** `POST /api/student-progress`
- **Recalcular** `POST /api/student-progress/recalc`

---

### ✅ Reportes
- Resultados de examen `GET /api/reports/exams/{exam}/results`
- Exportar CSV `GET /api/reports/exams/{exam}/results.csv`
- Historial de estudiante `GET /api/reports/students/{id}/history`

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

Las imágenes del documento `CTFG-DOC-18_Guia_para_Informe_Final_TFG 2025.docx` describen los siguientes flujos que **no están completamente implementados:**

---

### 3.1 Diagrama de secuencia del examen (image5)

El documento define estos estados del intento de examen:

```
Programado → Disponible → EnProgreso → Pausado → EnProgreso → Enviado → Calificado → ConRecomendaciones
```

| Paso | ¿Implementado? | Notas |
|------|---------------|-------|
| Crear examen (draft/programado) | ✅ | |
| Notificar disponibilidad al estudiante | ❌ | No hay evento ni notificación push/email |
| Iniciar examen (EnProgreso) | ✅ | `started_at` registrado |
| Temporizador activo | ❌ | `duration_minutes` se guarda pero no se valida en submit |
| **Pausar examen** | ❌ | No existe endpoint `PATCH /attempts/{id}/pause` |
| **Reanudar examen** | ❌ | No existe endpoint `PATCH /attempts/{id}/resume` |
| Enviar examen (Enviado) | ✅ | `submitted_at` registrado |
| Calificar automáticamente | ✅ | MC y TF auto-graded |
| Mostrar resultados | ✅ | |
| IA genera recomendaciones personalizadas | ⚠️ | Solo plantillas estáticas en el submit; OpenAI solo en regenerate |

---

### 3.2 Tutor IA conversacional (image11)

El documento describe un asistente IA completo con flujo de sesión:

```
Estudiante inicia sesión
    ↓
¿Primera vez en la sesión?
    Sí → Bienvenida + carga base del sistema
    No → Continúa directo
    ↓
Carga preferencia de estilo de aprendizaje (Visual / Auditivo / Lector)
    ↓
Dashboard con: Fortalezas, Debilidades, Áreas de mejora
    ↓
¿Qué desea hacer?
    1. Practicar → cargar ejercicios por tema con seguimiento
    2. Estudiar → cargar recursos + recomendaciones por materia
    3. Ir a examen → flujo de examen
    4. Preguntar sobre diagnóstico → chat libre con historial
    5. Ver mis resultados → análisis detallado
    6. ¿Darme alternativas? → genera ejemplos (texto/gráficos/audio)
    ↓
Fin de sesión → Actualiza perfil + guarda historial de conversación
```

**Lo que existe:**
- `POST /api/ai/generate` — prompt libre → respuesta OpenAI (sin contexto del estudiante)
- `POST /api/exam-attempts/{id}/recommendations/regenerate` — recomendaciones post-examen

**Lo que falta:**
- [ ] Tabla o campo para guardar historial de conversación por sesión
- [ ] Campo `learning_style` en el perfil del estudiante (Visual/Auditivo/Lector)
- [ ] Endpoint de sesión del tutor con contexto del estudiante (`/api/ai/tutor/session`)
- [ ] Endpoint de chat con historial (`POST /api/ai/tutor/chat`)
- [ ] Carga automática del diagnóstico al iniciar sesión del tutor
- [ ] Generación de ejemplos por tipo de aprendizaje

---

### 3.3 Casos de uso (image10)

| Caso de uso | Rol | Estado |
|-------------|-----|--------|
| Ver Exámenes | Estudiante | ✅ |
| Realizar Examen | Estudiante | ✅ |
| Ver Resultados | Estudiante | ✅ |
| Iniciar Sesión | Todos | ✅ |
| **Consultar IA Tutor** | Estudiante | ❌ Solo texto libre, no tutor contextual |
| Ver Progreso | Estudiante | ✅ |
| Acceder Calendario | Estudiante | ✅ |
| Solicitar Recursos | Estudiante | ✅ |
| Gestionar Estudiantes | Profesor/Admin | ✅ |
| Crear Exámenes | Profesor/Admin | ✅ |
| Asignar Grupos | Profesor/Admin | ✅ |
| **Ver Analíticas** | Profesor/Admin | ⚠️ Solo reportes básicos, no analíticas agregadas con gráficos |
| Generar Reportes | Profesor/Admin | ✅ |
| **Configurar Sistema** | Admin | ❌ No existe endpoint |
| Revisar Resultados | Profesor/Admin | ✅ |

---

### 3.4 Modelo de datos (image3)

El diagrama ER del documento muestra una entidad **`StudentSubject`** (inscripción explícita estudiante-materia) que no existe en el schema actual. Lo más cercano es `student_progress` (progreso por materia) pero no cubre el caso de "inscripción" de un estudiante a una materia específica.

**Campo huérfano:** `students.overall_average` — se inicializa en 0 al registrar pero ningún código lo actualiza.

---

### 3.5 Arquitectura (images 8 y 9)

El documento describe el frontend en **Next.js + Vercel + Recharts**. El backend Laravel actúa como API para esa arquitectura. Eso es correcto y compatible — no hay brecha aquí para el backend.

---

## 4. Bugs activos

> ✅ Todos los bugs identificados hasta el 21/04/2026 han sido corregidos.  
> Ver detalle completo en `INFORME_BUGS_ABRIL_2026.md`.

### Bugs corregidos en sesión 21/04/2026

| # | Archivo(s) | Descripción | Impacto | Estado |
|---|-----------|-------------|---------|--------|
| B1 | `AiRecommendationController.php` | Filtro `where('type')` apuntaba a columna inexistente — debía ser `recommendation_type` | Alto | ✅ Corregido |
| B2 | `Student.php` | Campo `year` existía en schema pero no en `$fillable` ni `$casts` | Alto | ✅ Corregido |
| B3 | `AiRecommendation.php` | Campo JSONB `resource` sin cast `array` — se leía como string | Medio | ✅ Corregido |
| B4 | `AiRecommendation.php` | `recommendation_type` sin cast a enum PHP — nuevo `AiRecommendationType` creado | Medio | ✅ Corregido |
| B5 | `ExamAttempt.php` | `grade_status` sin cast — nuevo `GradeStatus` enum creado | Medio | ✅ Corregido |
| B6 | `StudentAnswer.php` | `review_status` sin cast — nuevo `ReviewStatus` enum creado | Medio | ✅ Corregido |
| B7 | `CalendarEvent.php` | `event_type` sin cast — nuevo `CalendarEventType` enum creado | Medio | ✅ Corregido |
| B8 | `ExamController.php` | Activar examen con `available_until` expirado no era rechazado | Medio | ✅ Corregido |
| B9 | `AiRecommendationController.php`, `StudentAnswerController.php` | N+1 en `myRecommendations()`; query duplicada en `review()` | Medio | ✅ Corregido |
| B10 | `01_schema.sql` | `adecuacion_type` era `text` en DB pero enum en PHP — añadido tipo ENUM en PostgreSQL | Medio | ✅ Corregido |
| B11 | `ExamGradingService.php` | Comparación de IDs `bigserial` mediante `(int)` — cambiado a comparación `string` | Medio | ✅ Corregido |
| B12 | `ExamGradingService.php` | `correct_answer_snapshot` nunca se escribía al calificar | Bajo | ✅ Corregido |
| B13 | `app/Enums/CalendarTargetType.php` | Enum sin uso en ningún modelo, controlador ni schema — eliminado | Bajo | ✅ Corregido |
| B14 | `QuestionController.php`, `ExamGradingService.php` | `QuestionType::Essay` excluido de validación y sin lógica de calificación | Bajo | ✅ Corregido |

### Bugs corregidos en sesión 17/04/2026

| # | Archivo(s) | Descripción | Estado |
|---|-----------|-------------|--------|
| — | `AiRecommendation.php` | `'resource'` no estaba en `$fillable` | ✅ Corregido |
| — | `ExamAttemptRulesService.php` | `duration_minutes` no se validaba en submit | ✅ Corregido |
| — | `QuestionController.php` | `randomize_questions` no se aplicaba al cargar preguntas | ✅ Corregido |
| — | `StudentProgressService.php` | `overall_average` y `exams_completed_count` nunca se actualizaban | ✅ Corregido |

---

## 5. TODO priorizado

### ✅ Prioridad Alta — Completado (21/04/2026)

- [x] **Validar `duration_minutes` en submit** — `ExamAttemptRulesService::assertAttemptIsSubmittable()`, 30 s de gracia
- [x] **`'resource'` en fillable y cast array de AiRecommendation** — `AiRecommendation.php`
- [x] **Aplicar `randomize_questions`** — `QuestionController::index()` usa `inRandomOrder()`
- [x] **Actualizar `students.overall_average` y `exams_completed_count`** — `StudentProgressService::syncStudentStats()`

---

### 🟡 Prioridad Media — Completa lo que describe el documento

- [ ] **Pausa y reanudación del examen**
  - Crear endpoints: `PATCH /api/exams/{exam}/attempts/{attempt}/pause` y `/resume`
  - Agregar campo `paused_at` a `exam_attempts` (o llevar tiempo acumulado)
  - Considerar: tiempo de pausa no cuenta contra `duration_minutes`

- [ ] **Campo `learning_style` en perfil del estudiante**
  - Agregar columna en tabla `students`: `learning_style ENUM('visual','auditivo','lector') DEFAULT NULL`
  - Actualizar modelo `Student`, SQL schema, y endpoint de update de estudiante
  - Usar este campo en la generación de recomendaciones IA

- [ ] **Endpoint de sesión del Tutor IA contextual**
  - Ruta: `POST /api/ai/tutor/chat`
  - Payload: `{ message: string, session_id?: uuid }`
  - Lógica: cargar perfil del estudiante + historial de sesión + llamar OpenAI con contexto
  - Requiere tabla o campo para guardar historial de chat

- [ ] **Historial de conversación del tutor**
  - Nueva tabla: `ai_chat_sessions` (id, student_user_id, institution_id, messages JSONB, created_at, updated_at)
  - O campo `messages JSONB` en `ai_recommendations`

- [ ] **Notificación de disponibilidad de examen**
  - Cuando un examen pasa a estado `active`, notificar a los estudiantes de los grupos asignados
  - Opción mínima: endpoint `GET /api/students/me/available-exams` que devuelva exámenes activos disponibles para el estudiante logueado

- [ ] **Analíticas agregadas para profesor/admin**
  - Endpoint: `GET /api/analytics/institution` — estadísticas generales (total estudiantes, promedio general, exámenes completados)
  - Endpoint: `GET /api/analytics/subjects` — rendimiento por materia
  - Endpoint: `GET /api/analytics/students/{id}` — detalle completo de un estudiante

- [x] **Resolver el tipo `essay`** — ✅ Implementado (Opción B): validado en `QuestionController`, calificado como `needs_review` en `ExamGradingService`

---

### 🟢 Prioridad Baja — Mejoras y completitud

- [ ] **Endpoint de configuración del sistema**
  - Ruta: `GET/PUT /api/system/config`
  - Configuraciones básicas por institución: nombre, logo, timezone, etc.

- [ ] **`StudentSubject` — inscripción explícita a materias**
  - Si el documento lo requiere como entidad separada de `student_progress`
  - Nueva tabla pivot: `student_subjects (student_user_id, subject_id, institution_id, enrolled_at)`

- [ ] **Paginación en endpoints de lista grandes**
  - `GET /api/students` — agregar `?page=1&per_page=20`
  - `GET /api/exam-attempts` — igual
  - `GET /api/ai-recommendations` — igual

- [ ] **Validación de `subject_id` en ExamController**
  - Agregar `Rule::exists('subjects', 'id')` a la validación del examen

- [ ] **Endpoint `GET /api/students/me/available-exams`**
  - Devuelve exámenes activos asignados a los grupos del estudiante
  - Requiere cruzar: grupos del estudiante → exam_targets → exámenes activos

---

## 6. Referencia de endpoints existentes

### Públicos (sin auth)
| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/register` | Registro de usuario |
| POST | `/api/auth/login` | Login |
| POST | `/api/password/forgot` | Solicitar reset |
| POST | `/api/password/verify` | Verificar token |
| POST | `/api/password/reset` | Resetear contraseña |

### Autenticados — Solo estudiante
| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/exams/{exam}/attempts/start` | Iniciar intento |
| POST | `/api/exams/{exam}/attempts/{attempt}/submit` | Enviar intento |
| GET | `/api/ai-recommendations/me` | Mis recomendaciones |
| GET | `/api/student-progress/me` | Mi progreso |

### Autenticados — Admin, Profesor, Estudiante (lectura compartida)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/exams` | Lista exámenes |
| GET | `/api/exams/{exam}` | Ver examen |
| GET | `/api/exams/{exam}/questions` | Ver preguntas |
| GET | `/api/subjects` | Lista materias |
| GET | `/api/study-resources` | Lista recursos |
| GET | `/api/calendar-events` | Lista eventos |

### Autenticados — Admin y Profesor
| Método | Ruta | Descripción |
|--------|------|-------------|
| POST/PUT/DELETE | `/api/exams` | CRUD exámenes |
| POST/PUT/DELETE | `/api/exams/{exam}/questions` | CRUD preguntas |
| PUT/DELETE | `/api/questions/{question}` | Update/delete pregunta |
| POST/PUT/DELETE | `/api/subjects` | CRUD materias |
| POST/PUT/DELETE | `/api/groups` | CRUD grupos |
| POST/PUT/DELETE | `/api/study-resources` | CRUD recursos |
| POST/PUT/DELETE | `/api/calendar-events` | CRUD eventos |
| GET | `/api/students` | Lista estudiantes |
| GET/PUT/PATCH | `/api/students/{id}` | Ver/editar estudiante |
| GET | `/api/exam-attempts/{attempt}/answers` | Ver respuestas de intento |
| PATCH | `/api/student-answers/{answer}/review` | Revisar respuesta SA |
| GET | `/api/reports/exams/{exam}/results` | Reporte de examen |
| GET | `/api/reports/exams/{exam}/results.csv` | CSV de resultados |
| GET | `/api/reports/students/{id}/history` | Historial estudiante |
| POST | `/api/ai/generate` | Generar texto con IA |
| GET | `/api/ai-recommendations` | Lista recomendaciones |
| GET | `/api/ai-recommendations/{id}` | Ver recomendación |
| POST | `/api/exam-attempts/{attempt}/recommendations/regenerate` | Regenerar recomendaciones |
| POST | `/api/student-progress/recalc` | Recalcular progreso |

### Autenticados — Solo Admin
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET/PUT/PATCH | `/api/users` | CRUD usuarios |
| GET/POST/PUT/DELETE | `/api/institutions` | CRUD instituciones |

---

*Documento generado el 17/04/2026 tras análisis completo del código y las imágenes del documento CTFG-DOC-18.*
