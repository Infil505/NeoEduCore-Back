# Informe de Bugs — NeoEduCore
**Fecha:** 21 de abril de 2026 (actualizado 09 de mayo de 2026)  
**Rama:** Darwin  
**Estado al iniciar (17/04):** 82 tests pasando / 0 fallando  
**Estado al finalizar (09/05):** 142 tests pasando / 0 fallando  

---

## 1. Metodología de búsqueda

El análisis se realizó en dos etapas:

### 1.1 Revisión manual por categorías
Se inspeccionó el código fuente de forma sistemática en las siguientes categorías:

1. **Campos faltantes en `$fillable`** — se cruzaron los campos de cada tabla en `database/sql/01_schema.sql` contra los arrays `$fillable` de cada modelo Eloquent.
2. **Casts faltantes o incorrectos** — se identificaron columnas con tipos PostgreSQL (`ENUM`, `JSONB`) que no tenían cast correspondiente en `$casts` del modelo.
3. **Lógica de calificación** — se trazó el flujo completo de `ExamGradingService` pregunta por pregunta.
4. **Inconsistencias de estado** — se revisaron las transiciones del `ExamController::setStatus()` contra las reglas de negocio del TFG.
5. **Queries con N+1** — se buscaron loops o colecciones donde se accedía a relaciones sin eager loading previo.
6. **Validaciones faltantes en controladores** — se compararon los campos que se usan en la lógica de negocio contra los que se validan en el request.
7. **Campos huérfanos** — campos presentes en el schema SQL o en `$fillable` que nunca se escriben ni se leen.
8. **Enums inconsistentes** — se compararon los valores de los enums PHP contra los tipos `ENUM` definidos en PostgreSQL.

### 1.2 Herramientas utilizadas
- Lectura directa de archivos fuente (`app/Models/`, `app/Http/Controllers/`, `app/Services/`, `app/Enums/`)
- Búsqueda por patrones en todo el codebase (`grep` sobre campo y comparaciones)
- Revisión del schema SQL completo (`database/sql/01_schema.sql`)
- Ejecución del suite de tests como validación final (`php artisan test`)

---

## 2. Bugs encontrados y corregidos

### B1 — Filtro de tipo de recomendación roto *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Http/Controllers/AI/AiRecommendationController.php` |
| **Líneas** | 26, 51–52, 97, 108–109 |
| **Categoría** | Validación / nombre de columna incorrecto |

**Descripción:**  
En los métodos `index()` y `myRecommendations()`, la validación del filtro usaba la clave `'type'` y el `where()` filtraba por la columna `type`. Sin embargo, la columna real en la tabla `ai_recommendations` se llama `recommendation_type`. El filtro no producía ningún resultado ni error visible — simplemente no filtraba nada.

**Código antes:**
```php
$data = $request->validate([
    'type' => ['nullable', Rule::in(['strength', 'weakness', 'resource', 'action'])],
]);
// ...
$query->where('type', $data['type']); // columna inexistente
```

**Código después:**
```php
$data = $request->validate([
    'recommendation_type' => ['nullable', Rule::in(['strength', 'weakness', 'resource', 'action'])],
]);
// ...
$query->where('recommendation_type', $data['recommendation_type']);
```

---

### B2 — Campo `year` inaccesible en el modelo Student *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Models/Students/Student.php` |
| **Schema** | `database/sql/01_schema.sql` línea 116 |
| **Categoría** | Campo faltante en `$fillable` |

**Descripción:**  
La columna `year int` existe en la tabla `students` del schema SQL pero no estaba declarada en `$fillable` ni en `$casts` del modelo `Student`. Por el comportamiento de Eloquent (guarded by default), cualquier asignación masiva de `year` era silenciosamente ignorada y el campo quedaba siempre en `null`.

**Fix aplicado:**
```php
// $fillable — añadido:
'year',

// $casts — añadido:
'year' => 'integer',
```

---

### B3 — Campo `resource` (JSONB) leído como string *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Models/AI/AiRecommendation.php` |
| **Categoría** | Cast faltante |

**Descripción:**  
El campo `resource` es de tipo `JSONB` en PostgreSQL. Estaba en `$fillable` (añadido en sesión anterior) pero faltaba el cast `'array'` en `$casts`. Sin el cast, Eloquent devuelve el JSON como string crudo, lo que obliga a llamadas manuales a `json_decode()` en cada uso.

**Fix aplicado:**
```php
protected $casts = [
    'generated_at' => 'datetime',
    'resource'     => 'array',   // ← añadido
];
```

---

### B4 — `recommendation_type` sin cast a enum PHP *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Models/AI/AiRecommendation.php` |
| **Nuevo archivo** | `app/Enums/AiRecommendationType.php` |
| **Categoría** | Cast faltante / enum PHP inexistente |

**Descripción:**  
La columna `recommendation_type` en PostgreSQL es del tipo `ai_recommendation_type ENUM('strength','weakness','resource','action')`. El modelo devolvía el valor como string sin ninguna correspondencia a un tipo PHP fuerte. A diferencia de otros campos enum del proyecto, no existía una clase enum PHP para este campo.

**Fix aplicado:**

1. Creado `app/Enums/AiRecommendationType.php`:
```php
enum AiRecommendationType: string {
    case Strength = 'strength';
    case Weakness = 'weakness';
    case Resource = 'resource';
    case Action   = 'action';
}
```

2. Añadido import y cast en `app/Models/AI/AiRecommendation.php`:
```php
use App\Enums\AiRecommendationType;

protected $casts = [
    'generated_at'        => 'datetime',
    'resource'            => 'array',
    'recommendation_type' => AiRecommendationType::class,  // ← añadido
];
```

Con este cast, al leer una recomendación el campo devuelve una instancia `AiRecommendationType` en lugar de un string, y Eloquent lanza `ValueError` si el valor en BD no coincide con ningún case del enum.

---

### B5 — `grade_status` sin cast a enum PHP *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Models/Exams/ExamAttempt.php` |
| **Nuevo archivo** | `app/Enums/GradeStatus.php` |
| **Categoría** | Cast faltante / enum PHP inexistente |

**Descripción:**  
La columna `grade_status` en PostgreSQL es del tipo `grade_status ENUM('pending','graded','completed')`. El modelo `ExamAttempt` no tenía cast, devolviendo el valor como string y sin validación de tipo en PHP.

**Fix aplicado:**  
Creado `app/Enums/GradeStatus.php`:
```php
enum GradeStatus: string {
    case Pending   = 'pending';
    case Graded    = 'graded';
    case Completed = 'completed';
}
```
Cast añadido a `ExamAttempt.$casts`.

---

### B6 — `review_status` sin cast a enum PHP *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Models/Students/StudentAnswer.php` |
| **Nuevo archivo** | `app/Enums/ReviewStatus.php` |
| **Categoría** | Cast faltante / enum PHP inexistente |

**Descripción:**  
El campo `review_status` en la tabla `student_answers` almacena tres estados (`auto_graded`, `needs_review`, `reviewed`) definidos como comentario en el schema SQL ("si querés lo pasamos a enum"). El modelo no lo casteaba, retornando siempre un string plano.

**Fix aplicado:**  
Creado `app/Enums/ReviewStatus.php`:
```php
enum ReviewStatus: string {
    case AutoGraded  = 'auto_graded';
    case NeedsReview = 'needs_review';
    case Reviewed    = 'reviewed';
}
```
Cast añadido a `StudentAnswer.$casts`.

---

### B7 — `event_type` sin cast a enum PHP *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Models/Academic/CalendarEvent.php` |
| **Nuevo archivo** | `app/Enums/CalendarEventType.php` |
| **Categoría** | Cast faltante / enum PHP inexistente |

**Descripción:**  
La columna `event_type` es de tipo `calendar_event_type ENUM('exam','activity','reminder','meeting')` en PostgreSQL, pero el modelo `CalendarEvent` solo casteaba `start_at` y `end_at`. El valor se devolvía como string sin tipo PHP.

**Fix aplicado:**  
Creado `app/Enums/CalendarEventType.php` y cast añadido al modelo.

---

### B8 — Activación de examen sin validar ventana de disponibilidad *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Http/Controllers/Exams/ExamController.php` — `setStatus()` |
| **Línea** | ~187 |
| **Categoría** | Validación de estado faltante |

**Descripción:**  
Al transicionar un examen al estado `active`, no se verificaba si la ventana de disponibilidad (`available_until`) ya había expirado. Era posible activar un examen cuya fecha límite ya había pasado, haciéndolo inmediatamente inaccessible para los estudiantes (la validación de `assertExamIsStartable` rechazaría todos los intentos de inicio).

**Fix aplicado:**
```php
if ($next === ExamStatus::Active->value) {
    if ($exam->available_until && now()->gt($exam->available_until)) {
        return response()->json([
            'message' => 'No se puede activar: la ventana de disponibilidad ya expiró',
        ], 409);
    }
}
```

---

### B9 — N+1 en `myRecommendations()` y query innecesaria en `review()` *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivos** | `AiRecommendationController.php` + `StudentAnswerController.php` |
| **Categoría** | Query N+1 |

**Descripción (caso 1):**  
`myRecommendations()` no tenía eager loading. Al serializar la respuesta con relaciones `subject` o `exam`, Eloquent lanzaba una query adicional por cada recomendación retornada (hasta 15 queries extras por página).

**Fix:** añadido `->with(['subject', 'exam'])` al query builder.

**Descripción (caso 2):**  
En `StudentAnswerController::review()`, línea 85 usaba `$attempt->answers()->sum('points_awarded')` (query builder — nueva consulta SQL) mientras que línea 86 usaba `$attempt->answers->sum(...)` (colección ya cargada en línea 83). Inconsistencia que generaba una query redundante.

**Fix:** cambiado a `$attempt->answers->sum('points_awarded')` (colección).

---

### B10 — `adecuacion_type` como `text` en PostgreSQL pero enum en PHP *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `database/sql/01_schema.sql` línea 128 |
| **Categoría** | Inconsistencia schema DB vs modelo PHP |

**Descripción:**  
El modelo `Student` casteaba `adecuacion_type` al enum PHP `AdecuacionType`, pero en PostgreSQL la columna era `text`. Esto significaba que la base de datos no rechazaba valores inválidos — cualquier string podía guardarse directamente en la BD, y la restricción solo existía en la capa PHP.

**Fix aplicado en schema SQL:**
```sql
-- Añadido junto al bloque de tipos:
IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'adecuacion_type') THEN
    CREATE TYPE adecuacion_type AS ENUM ('acceso','contenido','evaluacion');
END IF;

-- Columna actualizada en tabla students:
adecuacion_type adecuacion_type,   -- antes: text
```

---

### B11 — Comparación de IDs `bigserial` mediante cast a `int` *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Services/Exams/ExamGradingService.php` líneas 45–46 |
| **Categoría** | Comparación de tipos insegura |

**Descripción:**  
Los IDs de `question_options` son `bigserial` (enteros de 64 bits). El código comparaba:
```php
$picked = (int) ($selectedIds[0] ?? 0);
$isCorrect = $picked === (int) $correctOption->id;
```
Usar `(int)` para comparar es potencialmente inseguro: si el ID llegase como string desde el cliente o excediese rangos en plataformas 32-bit, la comparación fallaría silenciosamente. Adicionalmente, si el cliente enviaba `0` (vacío), la comparación siempre sería `false` pero sin distinción del caso "no respondió".

**Fix aplicado:**
```php
$picked = (string) ($selectedIds[0] ?? '');
$isCorrect = $picked !== '' && $picked === (string) $correctOption->id;
```

---

### B12 — Campo `correct_answer_snapshot` nunca se escribía *(Bajo)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Services/Exams/ExamGradingService.php` |
| **Categoría** | Campo huérfano |

**Descripción:**  
El campo `correct_answer_snapshot` estaba definido en `$fillable` de `StudentAnswer` y casteado a `array`, diseñado para guardar un snapshot de la respuesta correcta en el momento de la calificación. Sin embargo, `ExamGradingService` nunca lo populizaba — siempre quedaba `null`.

**Fix aplicado** — ahora se guarda al calificar:
- **MC/TF:** `['option_text' => $correctOption->option_text]`
- **short_answer:** `['correct_answer_text' => $question->correct_answer_text]`
- **essay:** `null` (revisión manual, no aplica)

---

### B13 — Enum `CalendarTargetType` sin uso *(Bajo)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Enums/CalendarTargetType.php` (eliminado) |
| **Categoría** | Código muerto |

**Descripción:**  
El archivo `CalendarTargetType.php` definía un enum con cinco valores (`institution`, `grade`, `group`, `student`, `teacher`), pero no existía ninguna columna en el schema SQL que lo usara, ningún modelo lo importaba y ningún controlador lo referenciaba. Era código muerto sin ningún efecto.

**Fix:** archivo eliminado.

---

### B14 — `QuestionType::Essay` definido pero excluido de toda la lógica *(Bajo)*

| Campo | Valor |
|-------|-------|
| **Archivos** | `app/Enums/QuestionType.php`, `app/Http/Controllers/Exams/QuestionController.php`, `app/Services/Exams/ExamGradingService.php` |
| **Categoría** | Enum inconsistente / funcionalidad a medias |

**Descripción:**  
El valor `essay` existía tanto en el enum PHP `QuestionType` como en el tipo `question_type` de PostgreSQL, pero:
- La validación de `QuestionController::store()` lo excluía explícitamente (solo aceptaba `multiple_choice`, `true_false`, `short_answer`).
- `ExamGradingService` no tenía rama para `essay`.

Intentar crear una pregunta de tipo `essay` devolvía error 422.

**Fix aplicado:**
- Añadido `QuestionType::Essay->value` a la lista `Rule::in()` en `store()`.
- Añadida validación: `essay` no acepta opciones.
- En `ExamGradingService`: rama `essay` siempre marca la respuesta como `needs_review` con `points = 0` (requiere revisión manual, como `short_answer`).

---

## 3. Resumen ejecutivo — Sesión B (17–21/04/2026)

| Impacto | Cantidad | Bugs |
|---------|----------|------|
| Alto    | 2        | B1, B2 |
| Medio   | 8        | B3, B4, B5, B6, B7, B8, B9, B10, B11 |
| Bajo    | 3        | B12, B13, B14 |
| **Total** | **13** | |

> *Nota: B11 y B15 del análisis inicial se consolidaron en un único fix en `ExamGradingService`.*

**Archivos nuevos creados:**
- `app/Enums/AiRecommendationType.php`
- `app/Enums/GradeStatus.php`
- `app/Enums/ReviewStatus.php`
- `app/Enums/CalendarEventType.php`

**Archivos eliminados:**
- `app/Enums/CalendarTargetType.php`

---

## 4. Bugs encontrados y corregidos — Sesión D (09/05/2026, tests de integración)

Durante la escritura de los 60 tests de integración (Niveles 1–6) se detectaron y corrigieron los siguientes bugs reales que no habían sido detectados por los tests unitarios/feature anteriores.

---

### D1 — `syncGroups()` violaba NOT NULL en `exam_targets` *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Models/Exams/Exam.php` |
| **Categoría** | Pivot sin columna obligatoria |

**Descripción:** `groups()->sync()` no pasaba `institution_id` al pivot `exam_targets`, que tiene `institution_id NOT NULL`. Insertar un examen con grupos fallaba en producción con una excepción de violación de constraint.

**Fix:** `syncGroups()` helper en `Exam.php` usa `syncWithPivotValues(['institution_id' => $this->institution_id])`.

---

### D2 — `ExamController` usaba `sync()` en lugar de `syncGroups()` *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Http/Controllers/Exams/ExamController.php` — `store()`, `update()` |
| **Categoría** | Llamada al método incorrecto |

**Fix:** `groups()->sync()` → `$exam->syncGroups($groupIds)` en `store()` y `update()`.

---

### D3 — `selectedOptions()->sync()` violaba NOT NULL en `student_answer_options` *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Services/Exams/ExamGradingService.php` |
| **Categoría** | Pivot sin columna obligatoria |

**Descripción:** Al guardar opciones seleccionadas por el estudiante, el pivot `student_answer_options` requiere `institution_id NOT NULL`. Se usaba `sync()` sin ese valor.

**Fix:** `selectedOptions()->syncWithPivotValues(['institution_id' => $institutionId])`.

---

### D4 — `ExamAttemptController::show()` retornaba 404 en lugar de 403 para IDOR *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Http/Controllers/Exams/ExamAttemptController.php` — `show()` |
| **Categoría** | Código de respuesta HTTP incorrecto |

**Descripción:** Cuando un estudiante intentaba ver el intento de otro, el guard devolvía 404 en vez de 403, impidiendo distinguir "no existe" de "no tienes permiso".

**Fix adicional:** `diffInSeconds()` de Carbon 3.x devuelve `float`; PostgreSQL rechaza floats en columnas `integer`. Se añadió `(int)` en el cast.

---

### D5 — `SetTenantFromAuth` resolvía el usuario antes que `auth:sanctum` *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Http/Middleware/SetTenantFromAuth.php` |
| **Categoría** | Orden de middleware / resolución de usuario |

**Descripción:** El middleware usaba `auth()->user()` que devuelve `null` antes de que `auth:sanctum` haya procesado el token. El tenant nunca se seteaba.

**Fix:** Usar `auth()->guard('sanctum')->user()` que resuelve el guard de Sanctum directamente sin depender del orden de ejecución.

---

### D6 — `SetTenantFromAuth` no se ejecutaba antes de `SubstituteBindings` *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `bootstrap/app.php` |
| **Categoría** | Configuración de middleware |

**Descripción:** `SubstituteBindings` resuelve los modelos de ruta antes de que el tenant esté seteado, por lo que `TenantScoped` no filtraba correctamente los registros durante la resolución de parámetros de ruta.

**Fix:** `SetTenantFromAuth` prepended al grupo `api` (se ejecuta antes de `SubstituteBindings`).

---

### D7 — Desfase de 6 horas entre timestamps de BD y PHP *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `config/database.php` |
| **Categoría** | Configuración de zona horaria |

**Descripción:** El servidor PostgreSQL opera en UTC-6. Sin `'timezone' => 'UTC'` en la configuración de conexión, los timestamps se almacenaban con un desfase de 6 horas, causando que las comparaciones de tiempo (ventana disponible, tiempo restante de examen) fallaran en tests de integración.

**Fix:** `'timezone' => 'UTC'` en el array de conexión `pgsql`.

---

### D8 — `Student::groups()` con `withTimestamps(false)` generaba columna `""` *(Alto)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Models/Students/Student.php` |
| **Categoría** | Bug de API de Eloquent |

**Descripción:** `->withTimestamps(false)` pasa `false` como nombre de la columna `$createdAt` en Laravel's `BelongsToMany::withTimestamps($createdAt, $updatedAt)`. Eloquent generaba SQL con la columna vacía `""`, que PostgreSQL rechaza.

**Fix:** Eliminado `->withTimestamps(false)`. La tabla `group_students` no tiene timestamps, por lo que no se necesita la llamada.

---

### D9 — Ruta `/students/me/subjects` matcheaba como `/{student_user_id}` *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `routes/api.php` |
| **Categoría** | Orden de rutas en Laravel |

**Descripción:** La ruta dinámica `GET /students/{student_user_id}/subjects` se registraba antes que la literal `GET /students/me/subjects`. Laravel matcheaba `me` como valor del parámetro y el estudiante recibía 403 en lugar de sus materias.

**Fix:** Mover la ruta literal `/students/me/subjects` ANTES de las rutas parametrizadas `/{student_user_id}/subjects`.

---

### D10 — `Collection::takeLast()` no existe en Laravel *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Services/AI/AiTutorService.php` |
| **Categoría** | Método inexistente |

**Descripción:** `takeLast($n)` no existe en `Illuminate\Support\Collection`. Las llamadas al tutor fallaban con `BadMethodCallException`.

**Fix:** `->takeLast(n)` → `->take(-n)` (valor negativo toma los últimos N elementos).

---

### D11 — Claves de respuesta de `AnalyticsController` no coincidían con los tests *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Http/Controllers/Admin/AnalyticsController.php` |
| **Categoría** | Contrato de API inconsistente |

**Fix:** Renombradas claves: `average_score_percentage` → `average_score_pct`, `total_attempts` → `attempts_count`, `progress_by_subject` → `progress`.

---

### D12 — Clave `attempts` en `ReportController::examResults()` debía ser `results` *(Bajo)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `app/Http/Controllers/Admin/ReportController.php` |
| **Categoría** | Contrato de API inconsistente |

**Fix:** `'attempts' => $paginator` → `'results' => $paginator`.

---

### D13 — `InstitutionFactory` producía colisiones en el campo `code` *(Medio)*

| Campo | Valor |
|-------|-------|
| **Archivo** | `database/factories/Admin/InstitutionFactory.php` |
| **Categoría** | Espacio de valores insuficiente en factory |

**Descripción:** `bothify('INST-####')` genera solo 10.000 variantes (`0000`–`9999`). Al ejecutar el suite completo de 142 tests, que crea múltiples instituciones por test, la probabilidad de colisión era alta y causaba fallos intermitentes con `unique constraint violation` en `institutions_code_key`.

**Fix:** Código generado con los primeros 8 caracteres de un UUID: `'INST-' . strtoupper(substr(str_replace('-', '', Str::uuid()), 0, 8))` — 4+ billones de variantes únicas.

---

## 5. Resumen ejecutivo — Sesión D (09/05/2026)

| Impacto | Cantidad | Bugs |
|---------|----------|------|
| Alto    | 6        | D1, D2, D3, D5, D6, D7, D8 |
| Medio   | 5        | D4, D9, D10, D11, D13 |
| Bajo    | 1        | D12 |
| **Total** | **13** | |

**Resultado:** Suite completa pasó de 82 tests a **142 tests / 423 assertions / 0 fallando**.

---

*Análisis y correcciones de la Sesión B realizadas el 21/04/2026. Sesión D el 09/05/2026. Rama `Darwin`.*
