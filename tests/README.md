# API Tests Guide

Este proyecto contiene tests feature completos para todos los endpoints de la API REST.

## Estructura de Tests

```
tests/
├── Feature/
│   ├── Auth/
│   │   ├── AuthSessionTest.php           (GET /auth/me, POST /auth/logout)
│   │   ├── LoginRegisterTest.php         (Register, Login)
│   │   ├── PasswordResetTest.php         (Password reset & change)
│   │   └── SesionInactivaTest.php        (D3: caducidad por inactividad)
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
│   │   ├── ReportsTest.php               (Report endpoints)
│   │   └── ReportExportsTest.php         (B1/S4: XLSX y neutralización de fórmulas)
│   ├── Academic/
│   │   ├── BulkReassignmentTest.php      (Reasignación masiva: grupo y materias)
│   │   ├── ResetProgressTest.php         (Reseteo de progreso para repitentes)
│   │   ├── GroupStudentsTest.php         (Alta/baja de estudiantes en un grupo)
│   │   ├── CurricularMetadataTest.php    (D2: tema/indicador/dificultad y materia del recurso)
│   │   └── TopicMasteryTest.php          (D2: dominio por tema y reporte al docente)
│   ├── Exams/
│   │   └── AnswerLeakTest.php            (El alumno no ve la respuesta antes de entregar)
│   ├── Db/
│   │   ├── SchemaLoadedTest.php          (El schema de tests carga)
│   │   ├── SchemaIntegrityTest.php       (Invariantes: tipos uuid, unique de materia)
│   │   ├── EloquentRelationsMatchFksTest.php (Cada FK tiene su belongsTo; inversos de Institution y Subject)
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
│   │   ├── AiTutorEfficiencyTest.php     (Caché de contexto, JSONB incremental, límite global)
│   │   ├── AiTutorPrivacyTest.php        (Nada identificativo viaja a OpenAI)
│   │   ├── AiRecommendationsQueueTest.php (D1: análisis de IA diferido a la cola)
│   │   └── AiTutorIncidentsTest.php      (D4/D5: aviso de IA y registro de incidencias)
│   ├── Perf/
│   │   └── QueryBudgetTest.php           (Presupuesto de queries / guardia anti-N+1)
│   ├── Security/
│   │   └── AlcancePorRolTest.php         (S1: matriz de alcance por rol, endpoint por endpoint)
│   └── Routes/
│       ├── ProtectedRoutesRequireAuthTest.php
│       └── PublicRoutesTest.php
├── Traits/
│   ├── ApiAuth.php                       (signInTeacher, signInAdmin, signInStudent)
│   └── UsesPostgresSchema.php            (Recrea schema PostgreSQL desde 01_schema.sql)
└── TestCase.php                          (Base test class)
```

**Total: 449 tests, 1519 assertions** (13/09/2026)

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

Necesita un driver de cobertura: **PCOV** (recomendado, más rápido) o Xdebug. En Windows, PCOV para PHP 8.4 NTS x64:

1. Descargar `php_pcov-1.0.12-8.4-nts-vs17-x64.zip` de https://downloads.php.net/~windows/pecl/releases/pcov/1.0.12/
2. Copiar `php_pcov.dll` a `C:\php\ext` y añadir a `php.ini`: `extension=pcov` y `pcov.enabled=1`

```bash
php artisan test --coverage --min=70
```

Sin tocar `php.ini`, cargándolo solo para esa ejecución:

```bash
php -d extension=/ruta/php_pcov.dll -d pcov.enabled=1 -d pcov.directory=app vendor/bin/phpunit --coverage-text --only-summary-for-coverage-text
```

Último resultado (03/10/2026, 565 tests): **líneas 91,36 %**, métodos 71,08 %, clases 47,06 %. `phpunit.xml` excluye `app/Console` y `app/Support/ApiSpec.php` (herramientas de desarrollo).

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
- ✅ POST /password/forgot — mismas consultas para un correo registrado y uno inexistente (no se puede enumerar cronometrando); el job `EnviarEnlaceRecuperacion` ignora correos desconocidos
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

### Reasignación masiva — `BulkReassignmentTest` (22 tests)
- ✅ POST /bulk/reassign-group — por lista y por `from_group_id`; cierra membresía anterior
- ✅ `exclude_student_user_ids` con `from_group_id` (promoción sin repitentes en un paso); 422 con lista explícita o si se excluye a todos
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

### Notificaciones en la app — `Notifications/ExamAvailableNotificationTest` (6 tests)
- ✅ Activar un examen avisa exactamente a quien puede presentarlo: miembros vigentes de un grupo destino con cuenta activa (no a quien salió del grupo, a otro grupo, a suspendidos ni al docente)
- ✅ Publicar sin activar no avisa
- ✅ GET /notifications devuelve solo las propias, con `meta.unread_count` y filtro `?unread=1`
- ✅ Marcar como leída una ajena da 404; `read-all` marca todas
- ✅ Borrar el usuario borra sus avisos (FK `notifiable_id → users` en cascada)

### Formato del tutor por estilo — `AI/FormatoPorEstiloTest` (5 tests)
- ✅ Cada estilo del enum tiene su instrucción y son distintas; sin estilo no se añade nada
- ✅ El chat y el diagnóstico envían la instrucción al modelo y devuelven `presentation`

### Vídeo de apoyo del examen — `AI/VideoDelExamenTest` (7 tests)
- ✅ `video_url` opcional al crear; solo YouTube (rechaza otros dominios, sufijos engañosos y esquemas no http); `null` al editar lo quita
- ✅ El chat sobre el examen lo entrega a `visual` y `auditivo` (también en turnos siguientes de la sesión); `lector` y sin estilo reciben `video: null`
- ✅ `GET /exam-attempts/{id}/recommendations` lo trae según el estilo

### Chat asíncrono del tutor — `AI/TutorAsincronoTest` (7 tests)
- ✅ `async: true` responde 202 y la respuesta llega a la sesión (`GET /ai/tutor/sessions/{id}`)
- ✅ Con la respuesta en cola, `awaiting_reply: true` y un segundo mensaje da 409; una marca vieja no bloquea
- ✅ La inyección se contesta al momento sin encolar; sin `async` sigue siendo síncrono; sesión ajena → 404
- ✅ Si el job falla, el alumno recibe el mensaje de reserva y se desbloquea

### Drift esquema↔migraciones — `Unit/SchemaDriftTest` (5 tests)
- ✅ La comparación de `schema:check-drift` ignora solo las cabeceras de versión de `pg_dump` y los finales de línea; un cambio de columna o cualquier otro comentario sí cuenta
- El comando completo (base temporal + `pg_dump`) se ejecuta a mano: `php artisan --env=testing schema:check-drift`

### Relaciones Eloquent — `EloquentRelationsMatchFksTest` (2 tests)
- ✅ Cada FK de `pg_constraint` (salvo los 3 pivotes sin modelo) tiene un `belongsTo` con la misma columna, tabla y clave destino
- ✅ `Institution` y `Subject` declaran un `hasMany` por cada FK que les apunta

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
- ✅ El cupo de regeneraciones es por intento (`attempt_id`), no por examen+materia
- ✅ El recurso sugerido corresponde al grado del alumno

### Alcance por rol — `AlcancePorRolTest` (20 tests)
Tarea S1. Recorre la matriz de los 122 endpoints contra los cuatro roles preguntando
**qué ve cada uno cuando llega**, no solo si llega. Nació de encontrar una puerta
paralela: `/students` estaba acotado y `/users` devolvía a los mismos menores.
- ✅ Docente sin asignación: 0 alumnos en `/students`
- ✅ Docente sin asignación: **ni un alumno en `/users`** (el hallazgo)
- ✅ Docente sin asignación: 404 en la ficha `/users/{id}` de un alumno
- ✅ Docente sin asignación: 403 en ficha, materias, analíticas y los 5 reportes del alumno
- ✅ Docente sin asignación: sin progreso ni recomendaciones ajenas
- ✅ Docente sin asignación: sin grupos, y 403 en la lista nominal del aula
- ✅ Docente asignado: ve al suyo y no al de al lado, por `/students` **y** por `/users`
- ✅ El docente sigue viendo al personal del centro (la frontera es la de los menores)
- ✅ El docente solo ve las analíticas de sus materias; el admin, las de todas (S5)
- ✅ Un docente no edita ni borra el evento ni el recurso de otro (S6)
- ✅ El docente sí edita y borra lo suyo, y el admin puede con lo de cualquiera
- ✅ Una entrada sin autor (`created_by` nulo) solo la toca el admin
- ✅ El estudiante no abre el intento de otro (404/403)
- ✅ El estudiante no entra a ninguna ruta de gestión
- ✅ El admin no alcanza a nadie de otro centro
- ✅ El superadmin no entra a los datos de una institución
- ✅ El admin no entra a las rutas de plataforma

### Caducidad de sesión por inactividad — `SesionInactivaTest` (6 tests)
Decisión D3. Los dos casos que importan son opuestos y están los dos.
- ✅ Token sin usar más de una hora → 401
- ✅ Token usado hace 59 min → sigue valiendo
- ✅ **Sesión de 5 h pero activa → no caduca** (es el examen largo)
- ✅ Token recién emitido sin `last_used_at` → vale
- ✅ El tope absoluto de 12 h manda aunque se acabe de usar
- ✅ Con `inactivity_minutes = 0` la comprobación queda desactivada

### Registro del tutor por grado — `RegistroPorGradoTest` (6 tests)
Entre 1.º y 6.º hay seis años de diferencia lectora. Antes solo el chat sabía el
grado, y como número suelto; diagnóstico y recomendaciones ni lo recibían.
- ✅ Cada franja (1–2, 3–4, 5–6) tiene su registro y son distintos entre sí
- ✅ Los tres prompts describen la etapa desde configuración (`primaria (6 a 12 años)`)
- ✅ Sin grado se usa el registro conservador
- ✅ El chat manda el registro del grado del alumno
- ✅ El diagnóstico también
- ✅ Y las recomendaciones post-examen

### Aviso de IA e incidencias — `AiTutorIncidentsTest` (10 tests)
Decisiones D4 y D5. El que más importa es el cuarto: la tabla de incidencias no
puede acabar guardando el dato personal que motivó el bloqueo.
- ✅ El chat devuelve `ai_notice`
- ✅ El diagnóstico también
- ✅ Un bloqueo por PII deja fila, con tipo, etapa, alumno e institución
- ✅ **La incidencia no guarda el texto que la provocó**
- ✅ Un enlace fuera de la lista blanca se registra aunque la respuesta sí se entregue
- ✅ Un fallo de OpenAI se registra como `model_error`
- ✅ El superadmin ve agregados, y ni el id ni el nombre del alumno
- ✅ Sin mensajes la tasa es 100 %, y los tipos sin incidencias salen en 0
- ✅ 403 para el admin de un centro
- ✅ 403 para el docente

### Metadatos curriculares — `CurricularMetadataTest` (8 tests)
Decisión D2: `questions` gana tema, indicador y dificultad; `study_resources` gana materia.
- ✅ Crear pregunta con `topic`, `indicator` y `difficulty`
- ✅ Una dificultad fuera de los tres niveles se rechaza (422)
- ✅ Los metadatos son opcionales: la pregunta sin ellos se sigue creando
- ✅ `topic_normalized` la calcula la base, no el código (escritura directa por Eloquent)
- ✅ Recurso con materia, y filtro `?subject_id=`
- ✅ 422 al colgar un recurso de la materia de otro centro
- ✅ El recurso sugerido es de la materia del examen, no el más reciente del centro
- ✅ La materia pesa más que el grado cuando no hay nada del grado

### Dominio por tema — `TopicMasteryTest` (9 tests)
- ✅ «Fracciones», «fracciones » y «  FRACCIONES   » cuentan como un solo tema
- ✅ Los espacios interiores de más tampoco crean temas nuevos
- ✅ Un tema con menos de 3 respuestas no se reporta
- ✅ Las preguntas sin `topic` se ignoran
- ✅ Los temas salen del más flojo al más sólido
- ✅ El docente solo ve los temas de sus grupos, y sin identificadores de alumno
- ✅ El admin ve los de toda su institución
- ✅ El diagnóstico del tutor menciona los temas flojos y nunca el nombre
- ✅ 403 para el estudiante en `/reports/topics`

### Análisis de IA en cola — `AiRecommendationsQueueTest` (7 tests)
Decisión D1: la entrega deja plantillas y el análisis del modelo se encola al
consultar los resultados. Lo que se vigila es el reparto entre los dos pasos.
- ✅ La entrega no encola ni llama al modelo
- ✅ Abrir los resultados encola el análisis, y recargar no vuelve a encolar
- ✅ El job sustituye las plantillas por las 4 secciones del modelo y deja `ready`
- ✅ **El prompt lleva tema, indicador y dificultad — y nunca la respuesta correcta**
- ✅ Si el modelo no responde: no se escribe ni se borra nada, y `failed` solo tras agotar reintentos
- ✅ 403 sobre el intento de otro alumno
- ✅ 409 si el intento aún no se entregó

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

### Exportación de reportes a fichero (7 tests)
`ReportExportsTest`. CSV y XLSX salen del mismo dataset, así que lo que se vigila
es que no diverjan y que ninguna celda acabe siendo una fórmula.
- ✅ GET /reports/exams/{exam}/results.xlsx — content-type y cabeceras idénticas al CSV
- ✅ GET /reports/students/{student}/history.xlsx — cabeceras idénticas al CSV
- ✅ El XLSX escribe la nota como número y `submitted_at` como fecha de Excel
- ✅ Un `full_name` que empieza por `=` no se guarda como fórmula (CSV y XLSX)
- ✅ Un título de examen que empieza por `=` tampoco
- ✅ 403 para el docente ajeno al examen
- ✅ 403 para el docente no asignado al estudiante

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

**Total: 449 tests, 1519 assertions** (13/09/2026)

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
