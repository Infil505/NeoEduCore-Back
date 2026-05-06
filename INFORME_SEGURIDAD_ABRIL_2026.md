# Informe de Seguridad — NeoEduCore
**Fecha:** 21 de abril de 2026  
**Rama:** Darwin  
**Metodología:** Revisión estática de código + análisis de flujo de datos  
**Estado al iniciar:** 82 tests pasando / 0 fallando  
**Estado al finalizar:** 82 tests pasando / 0 fallando  

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

## 4. Resumen ejecutivo

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

**Tests antes/después:** 82 pasando / 0 fallando en ambos casos.  
Los tests de `AiRecommendationsTest` y `StudentProgressTest` fueron actualizados para reflejar los nuevos controles de autorización, verificando que los escenarios legítimos (docente accediendo a datos de sus propios estudiantes) siguen funcionando correctamente.

---

*Revisión realizada el 21/04/2026 sobre la rama `Darwin`.*
