# Informe de Seguridad — NeoEduCore
**Fecha:** 21 de abril de 2026 (actualizado 09 de mayo de 2026)  
**Rama:** Darwin  
**Metodología:** Revisión estática de código + análisis de flujo de datos  
**Estado al iniciar (17/04):** 82 tests pasando / 0 fallando  
**Estado al finalizar (09/05):** 142 tests pasando / 0 fallando  

> **📁 DOCUMENTO HISTÓRICO — cerrado el 09/05/2026.** Registra la revisión de seguridad hecha
> entre el 17/04 y el 09/05/2026. **No refleja el estado actual y no debe leerse como que la
> seguridad quedó auditada:** hubo al menos dos rondas de hallazgos posteriores, ambas
> encontradas de casualidad y no auditando —la filtración de respuestas (G12) en
> `ESTADO_Y_PENDIENTES.md`, y cuatro hallazgos más en `ANALISIS_MODELO_DATOS_TFG.md` §9.8.3—.
> **La revisión sistemática de seguridad por rol sigue pendiente.**

---

## 1. Metodología

### 1.1 Proceso de revisión
La revisión se realizó en tres fases:

1. **Identificación** — análisis sistemático del código fuente en las siguientes categorías de seguridad:
   - Autorización e IDOR (Insecure Direct Object Reference)
   - Escalada de privilegios intra-tenant (docente vs. docente)
   - Manipulación de lógica de negocio
   - Exposición de datos sensibles

2. **Filtrado de falsos positivos** — cada hallazgo fue verificado de forma independiente trazando el flujo de datos desde la entrada hasta el recurso afectado, evaluando si los controles existentes (TenantScoped, middleware de roles) mitigaban la vulnerabilidad.

3. **Corrección y tests** — cada fix fue aplicado y validado contra el suite de 82 tests.

### 1.2 Controles de seguridad existentes (contexto)
| Control | Alcance | Limitación |
|---------|---------|------------|
| `auth:sanctum` | Requiere token válido | No distingue propiedad de recursos |
| `tenant` middleware (`SetTenantFromAuth`) | Aísla datos por `institution_id` | No aísla por docente dentro de la misma institución |
| `RequireRole` middleware | Verifica rol (admin/teacher/student) | No verifica relación dueño-recurso |
| `TenantScoped` trait | Filtra queries por `institution_id` | Solo previene acceso inter-institución |

---

## 2. Vulnerabilidades encontradas y corregidas

---

### S1 — IDOR en `StudentAnswerController` *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivos** | `app/Http/Controllers/Students/StudentAnswerController.php:15` (index) y `:44` (review) |
| **Tipo** | Broken Access Control / IDOR |
| **Severidad** | Alto |
| **Confianza** | 9/10 |

**Descripción:**  
Los métodos `index()` y `review()` no verificaban que el docente autenticado fuera el creador del examen al que pertenece el intento. Cualquier docente de la misma institución podía leer las respuestas de exámenes de otros docentes y calificar manualmente preguntas de respuesta corta en exámenes ajenos.

**Escenario de explotación:**  
El Profesor B obtiene el UUID de un intento del Profesor A (visible en la ruta de submit o en reportes) y llama a `GET /exam-attempts/{uuid}/answers`, obteniendo todas las respuestas del estudiante incluyendo respuestas de desarrollo. Luego puede llamar a `PATCH /student-answers/{id}/review` y modificar la calificación de ese intento.

**Código vulnerable:**
```php
// Antes — sin ninguna verificación de propiedad:
public function index(Request $request, ExamAttempt $attempt)
{
    return response()->json([
        'data' => $attempt->answers()->with('question')->get(),
    ]);
}
```

**Fix aplicado:**
```php
// Después — teacher solo accede a intentos de sus propios exámenes:
public function index(Request $request, ExamAttempt $attempt)
{
    $user = $request->user();
    if ($user->user_type->value === 'teacher') {
        $attempt->loadMissing('exam');
        if ($attempt->exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }
    }
    return response()->json([
        'data' => $attempt->answers()->with('question')->get(),
    ]);
}
// Mismo control aplicado en review()
```

---

### S2 — IDOR en mutaciones de exámenes y preguntas *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivos** | `app/Http/Controllers/Exams/ExamController.php` — `update()`, `setStatus()`, `destroy()` / `app/Http/Controllers/Exams/QuestionController.php` — `store()`, `update()`, `destroy()` |
| **Tipo** | Broken Access Control / Privilege Escalation intra-tenant |
| **Severidad** | Alto |
| **Confianza** | 9/10 |

**Descripción:**  
Ninguno de los 6 métodos de mutación verificaba que el docente autenticado fuera el creador del examen. Cualquier docente de la misma institución podía:
- Editar el título, instrucciones o configuración de exámenes ajenos
- Cambiar el estado de un examen a `active` o `completed`
- Eliminar exámenes en estado `draft` de otros docentes
- Agregar, modificar o eliminar preguntas en exámenes ajenos

**Escenario de explotación:**  
El Profesor B llama a `POST /exams/{exam_del_profesor_A}/questions` y añade preguntas incorrectas al examen antes de que los estudiantes lo tomen. O llama a `PATCH /exams/{exam_id}` con `{"status": "completed"}` para cerrar anticipadamente el examen activo de otro colega.

**Código vulnerable:**
```php
// ExamController::update() — sin verificación de propiedad:
public function update(Request $request, Exam $exam)
{
    if (!in_array($exam->status->value, [...], true)) { ... }
    // ← exam->created_by_teacher_id nunca se verifica
```

**Fix aplicado (patrón uniforme en los 6 métodos):**
```php
// Admin puede operar sobre cualquier examen de la institución.
// Teacher solo puede operar sobre sus propios exámenes.
$user = $request->user();
if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
    return response()->json(['message' => 'No autorizado'], 403);
}

// Para métodos de Question donde el exam se obtiene del question:
$question->loadMissing('exam');
if ($user->user_type->value === 'teacher' && $question->exam->created_by_teacher_id !== $user->id) {
    return response()->json(['message' => 'No autorizado'], 403);
}
```

---

### S3 — Manipulación arbitraria de progreso académico *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Http/Controllers/Students/StudentProgressController.php:88` |
| **Tipo** | Business Logic Bypass / Integrity Violation |
| **Severidad** | Medio |
| **Confianza** | 8/10 |

**Descripción:**  
El endpoint `POST /student-progress` permitía a cualquier docente establecer directamente el valor `mastery_percentage` (0–100) de cualquier estudiante de la institución, sin ninguna relación con resultados reales de exámenes y sin restricción de si el docente enseña a ese estudiante. No existía registro de auditoría de quién realizó el cambio.

**Escenario de explotación:**  
Un docente llama a `POST /student-progress` con `{"student_user_id": "uuid-estudiante", "mastery_percentage": 100}` para inflar artificialmente las métricas de desempeño de sus estudiantes, afectando el dashboard del estudiante, los reportes y las recomendaciones generadas por IA.

**Código vulnerable:**
```php
// Antes — cualquier teacher puede modificar a cualquier estudiante:
public function upsert(Request $request)
{
    // Solo valida rangos numéricos, no la relación docente-estudiante
    Student::where('user_id', $data['student_user_id'])->firstOrFail();
    // ← sin verificar que el teacher enseña a este estudiante
    StudentProgress::updateOrCreate([...]);
}
```

**Fix aplicado:**
```php
// Teacher solo puede actualizar progreso de estudiantes
// que pertenecen a grupos vinculados a sus propios exámenes:
if ($user->user_type->value === 'teacher') {
    $studentGroupIds = Student::where('user_id', $data['student_user_id'])
        ->firstOrFail()
        ->groups()
        ->pluck('groups.id');

    $teacherGroupIds = Exam::where('created_by_teacher_id', $user->id)
        ->with('groups')
        ->get()
        ->flatMap(fn ($e) => $e->groups->pluck('id'));

    if ($studentGroupIds->intersect($teacherGroupIds)->isEmpty()) {
        return response()->json(['message' => 'No autorizado'], 403);
    }
}
```

---

### S4 — Exposición de recomendaciones IA de estudiantes ajenos *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Http/Controllers/AI/AiRecommendationController.php:63` |
| **Tipo** | Information Disclosure / Broken Access Control |
| **Severidad** | Medio |
| **Confianza** | 9/10 |

**Descripción:**  
El método `show()` verificaba la propiedad únicamente para el rol `student`. Los docentes podían recuperar cualquier recomendación IA de la institución sin restricción. Las recomendaciones contienen diagnósticos pedagógicos generados por GPT (fortalezas, debilidades, áreas específicas de mejora) que son datos sensibles del rendimiento académico del estudiante.

**Escenario de explotación:**  
El Profesor B llama a `GET /ai-recommendations/{uuid}` con el ID de una recomendación de un estudiante que no pertenece a su aula, y obtiene el diagnóstico completo incluyendo texto generado con análisis de errores específicos en exámenes de otro docente.

**Código vulnerable:**
```php
// Antes — solo bloquea al student, teacher tiene acceso irrestricto:
if ($user->user_type->value === 'student'
    && $aiRecommendation->student_user_id !== $user->id) {
    return response()->json(['message' => 'No autorizado'], 403);
}
// ← teacher B puede ver recomendaciones del aula del teacher A
```

**Fix aplicado:**
```php
// Después — teacher solo ve recomendaciones de sus propios exámenes:
if ($user->user_type->value === 'student' && $aiRecommendation->student_user_id !== $user->id) {
    return response()->json(['message' => 'No autorizado'], 403);
}

if ($user->user_type->value === 'teacher') {
    $aiRecommendation->loadMissing('exam');
    if ($aiRecommendation->exam === null
        || $aiRecommendation->exam->created_by_teacher_id !== $user->id) {
        return response()->json(['message' => 'No autorizado'], 403);
    }
}
```

---

## 3. Hallazgos descartados (falsos positivos)

| Hallazgo evaluado | Razón del descarte |
|-------------------|--------------------|
| Mass assignment de `institution_id` | `StudentController::update()` usa `$request->validate()` con whitelist explícita — `institution_id` nunca llega al modelo |
| CSV injection en `ReportController` | Usa `fputcsv()` que escapa automáticamente todos los valores; los datos de estudiante no son controlados por el estudiante en el CSV |
| Prompt injection en OpenAI | Los datos de respuestas son texto académico; la API de OpenAI tiene controles de contenido propios |
| Race condition en submit/start | Mitigadas por constraints UNIQUE en DB (`attempt_id + question_id`); las transacciones de PostgreSQL previenen duplicados |

---

## 4. Resumen ejecutivo — Sesión S (17–21/04/2026)

| # | Vulnerabilidad | Archivos afectados | Severidad | Estado |
|---|----------------|--------------------|-----------|--------|
| S1 | IDOR en lectura y revisión de respuestas | `StudentAnswerController.php` | 🔴 Alto | ✅ Corregido |
| S2 | IDOR en mutaciones de exámenes y preguntas | `ExamController.php`, `QuestionController.php` | 🔴 Alto | ✅ Corregido |
| S3 | Manipulación arbitraria de progreso académico | `StudentProgressController.php` | 🟡 Medio | ✅ Corregido |
| S4 | Exposición de recomendaciones IA ajenas | `AiRecommendationController.php` | 🟡 Medio | ✅ Corregido |

**Principio de seguridad aplicado en todos los fixes:**  
`admin` puede operar sobre cualquier recurso de su institución.  
`teacher` solo puede operar sobre recursos vinculados a los exámenes que creó.  
`student` solo puede acceder a sus propios datos.

---

## 5. Mejoras de seguridad adicionales — Sesión 09/05/2026

Las siguientes mejoras de seguridad proactivas se implementaron durante la sesión de brechas TFG:

| # | Mejora | Archivo | Estado |
|---|--------|---------|--------|
| S5 | **Rate limiting en IA** — `throttle:20,1` en `/ai/generate`; `throttle:30,1` en `/ai/tutor/chat`; `throttle:10,1` en diagnóstico | `routes/api.php` | ✅ |
| S6 | **Headers de seguridad HTTP** — middleware `SecurityHeaders` añade `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `HSTS` | `app/Http/Middleware/SecurityHeaders.php` | ✅ |
| S7 | **Validación de output IA** — `AiOutputValidator` verifica longitud mínima/máxima, ausencia de datos personales, URLs solo de whitelist verificada | `app/Services/AI/AiOutputValidator.php` | ✅ |
| S8 | **RBAC con middleware** — `RequireRole` middleware reemplaza chequeos inline; rutas agrupadas por rol en `api.php` | `app/Http/Middleware/RequireRole.php` | ✅ |
| S9 | **Tests de RBAC/IDOR** — Level 2 integration tests cubren acceso cruzado de intentos, exámenes, cross-tenant (12 tests) | `tests/Feature/Integration/Level2_RbacIdorTest.php` | ✅ |

---

## 6. Revisión de los cambios de la rama `Joseph` (merge del 06/10/2026)

Revisión solo documental: **no se modificó código de aplicación**. Commits revisados: `6c09218` (caché fuera del repo) y `1fd79da` («caso 22»). Suite tras el merge: 754 verdes y 1 fallo (un test de `BulkUploadStudentsTest` comparaba contra el total global de `group_students`; se ajustó a «antes/después»; ese archivo pasa entero, la suite completa no se repitió tras el ajuste).

### 6.1 Cambios que mejoran la seguridad

| # | Cambio | Archivo | Efecto |
|---|--------|---------|--------|
| J1 | `/overview` aplica `visibleTo($user)` a recursos y eventos | `OverviewController.php` | Cierra una **fuga de información**: el docente veía recursos y avisos de colegas, y el alumno los de todo el centro. Del autor solo se devuelve `id` y `full_name`. |
| J2 | Las recomendaciones de IA solo sugieren recursos que el alumno puede abrir | `AiRecommendationService.php` | Antes valía cualquier recurso del centro (de otros docentes o sin aula); ahora solo los de aulas donde está matriculado (`left_at IS NULL`). |
| J3 | `PUT /students/{id}` rechaza `grade`, `section` y `group_code` con 422 | `StudentController.php` | La matrícula solo cambia moviendo al alumno de aula. Evita que la ficha y la matrícula queden desincronizadas y reduce la superficie de asignación masiva. |
| J4 | El correo de contacto pasa de `settings` a la columna `institutions.email` | `SystemConfigController.php` | Sin impacto de seguridad; un solo origen del dato. |
| J5 | `storage/framework/cache` deja de versionarse | `.gitignore` | El repo es público. Lo que había en caché eran marcas de tiempo (`1791265940i:…`), sin datos personales ni secretos; el historial las conserva, riesgo nulo. |

### 6.2 Cambios nuevos que conviene vigilar

| # | Observación | Severidad | Detalle y recomendación (sin aplicar) |
|---|-------------|-----------|---------------------------------------|
| O-J1 | Enlace de alta **en cola** (`EnviarEnlaceDeAlta`) | 🟡 Media | Depende de que `queue:work` esté activo. Si el worker cae, las cuentas quedan `inactive` sin enlace y la respuesta de la carga ya no informa fallos de envío (`emailFailures` desapareció): la API contesta «N correos encolados» aunque no se envíen. Hay 3 reintentos y después el fallo queda solo en `failed_jobs`. Revisar monitorización de la cola y valorar un reenvío de enlace para cuentas inactivas. |
| O-J2 | **Un mismo `password_hash` inservible para todas las cuentas de una carga** | 🟢 Baja | Se genera un hash por carga (no por fila) de una contraseña aleatoria de 40 caracteres que se descarta. No permite entrar (el secreto no existe), pero todas las cuentas de una carga comparten hash. Aceptable; el login debe seguir rechazando cuentas `inactive`, y el reset/alta debe sobrescribir el hash. Pendiente de confirmar que ningún flujo trate el hash compartido como credencial válida. |
| O-J3 | `dry_run` ejecuta la carga real y la revierte | 🟢 Baja | Mismas validaciones que la carga real, sin segunda copia de reglas. No se envían correos (van tras el commit). Efectos laterales: consume secuencias y mantiene la transacción abierta durante el proceso; la ruta está bajo `throttle:bulk-upload` (también para `dry_run`). |
| O-J4 | Enumeración de correos en la carga masiva | 🟢 Baja (preexistente) | El mensaje «el correo X ya está en uso» revela si un correo existe en **cualquier** institución (`users.email` es único global). Solo lo ve un administrador autenticado y con límite de peticiones, pero la vista previa lo hace más cómodo de explotar. Opción: mensaje genérico («correo no disponible»). |
| O-J5 | Tarea en cola sin contexto de institución | 🟢 Baja | `EnviarEnlaceDeAlta` carga el usuario con `withoutGlobalScopes()` por id. Es correcto porque el worker no tiene tenant, y solo actúa si la cuenta sigue `inactive`. Mantener ese control si se amplía el job. |

### 6.3 Seguimiento (07/10/2026)

- **O-J1 corregido.** El fallo era peor de lo descrito: `PasswordSetupService::sendSetupLink` es best-effort (se traga el error y devuelve `false`) y `EnviarEnlaceDeAlta` ignoraba ese resultado, así que un fallo al preparar el token o encolar el correo terminaba «bien»: sin reintento y sin fila en `failed_jobs`. Ahora el job lanza excepción si el enlace no se preparó y reintenta con `backoff` 30/120 s. Quien no reciba el enlace puede pedirlo en `/password/forgot` (acepta cuentas `inactive`). Sigue sin existir un reenvío por parte del administrador. Tests: `AtaquesALaColaDeAltaTest`.
- **O-J6 (nuevo, corregido): `/dashboard/staff-overview` devolvía a cualquier docente los últimos 20 usuarios del centro, alumnos incluidos** (nombre y correo de menores de aulas que no tiene asignadas). Misma puerta que se cerró en `UserController::index()`. Ahora el docente recibe el personal del centro y solo sus alumnos.
- J1 y J3 probados con ataques: `AtaquesAlOverviewYALaFichaTest` (panel del docente y del alumno, ficha editada por el alumno, mover de aula por la ficha).
- **O-J4 sin cambiar:** cualquier rechazo por correo existente revela que existe; un mensaje genérico no lo evita, solo ocultaría de qué centro es. Queda como riesgo aceptado salvo decisión contraria.

---

*Revisión de la Sesión S realizada el 21/04/2026. Mejoras adicionales (S5–S9) el 09/05/2026. Rama `Darwin`.*
