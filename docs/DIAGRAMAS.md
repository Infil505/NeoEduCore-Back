# NeoEduCore — Diagramas del sistema

**Generado el 8 de agosto de 2026, desde el código de la rama `Darwin`.**

> Estos diagramas describen el **sistema construido**, no el diseño previsto. Se derivan de
> `database/sql/01_schema.sql` (artefacto de `pg_dump` contra producción), `routes/api.php`,
> `app/Models/`, `app/Services/` y `app/Http/Controllers/`. Cuando el informe del TFG y esto
> no coincidan, manda esto: las divergencias están inventariadas en
> [`ANALISIS_MODELO_DATOS_TFG.md`](ANALISIS_MODELO_DATOS_TFG.md) §10.
>
> **Para el informe.** Cubren las figuras 2 a 10 del índice de figuras y añaden el modelo
> entidad-relación, que el documento no tiene (§9.2 de aquel análisis).

## Cómo se trabaja con este documento

**Este `.md` es la fuente.** Los diagramas se escriben aquí una sola vez, en Mermaid.

**Para verlos en grande hay una versión HTML**, con cada figura a ancho completo, zoom y
pantalla completa:

```bash
php artisan diagramas:html      # genera docs/diagramas.html
```

`docs/diagramas.html` es un **artefacto derivado**, igual que `api-docs.json` o la colección
de Postman: si se edita a mano, se pierde en la siguiente ejecución. Existe porque en la
columna de texto Mermaid encoge el SVG para que quepa, y un ERD o un diagrama de clases se
vuelve ilegible; la página los saca del flujo y les da desplazamiento y zoom.

**Validación.** Los diagramas se comprueban con el parser real de Mermaid, sin navegador:

```bash
npm i mermaid jsdom
node validar.mjs docs/DIAGRAMAS.md
```

> En Node 24 `globalThis.navigator` solo tiene *getter*, así que el JSDOM se inyecta con
> `Object.defineProperty`, no con una asignación directa.

Si cambia el esquema, las cifras de la §1 se recomprueban con:

```bash
grep -c "ADD CONSTRAINT .* FOREIGN KEY" database/sql/01_schema.sql   # 51
grep -c "^CREATE TABLE public\."        database/sql/01_schema.sql   # 25 (20 dominio + 5 framework)
```

---

## Índice

1. [Modelo entidad-relación](#1-modelo-entidad-relación) — [mapa](#11-mapa-de-relaciones) · [personas](#12-personas-y-estructura-académica) · [evaluación](#13-evaluación) · [seguimiento](#14-seguimiento-tutor-ia-y-apoyo)
2. [Aislamiento multi-tenant](#2-aislamiento-multi-tenant)
3. [Diagrama de clases](#3-diagrama-de-clases) — [personas y traits](#31-personas-estructura-y-los-traits-de-seguridad) · [evaluación](#32-evaluación) · [seguimiento e IA](#33-seguimiento-ia-y-apoyo)
4. [Arquitectura del backend](#4-arquitectura-del-backend)
5. [Flujo de autenticación](#5-flujo-de-autenticación)
6. [Flujo de examen](#6-flujo-de-examen)
7. [Flujo del tutor IA](#7-flujo-del-tutor-ia)
8. [Diagrama de casos de uso](#8-diagrama-de-casos-de-uso)
9. [Diagrama de proceso: el ciclo académico](#9-diagrama-de-proceso-el-ciclo-académico)

---

## 1. Modelo entidad-relación

**20 tablas de dominio** — 15 entidades y 5 pivotes — con **51 claves foráneas**. Aparte
quedan 5 tablas de framework (`migrations`, `jobs`, `failed_jobs`, `password_reset_tokens`,
`personal_access_tokens`), que no son del dominio.

Va en **cuatro vistas**, y no por capricho de maquetación: un ERD de 20 entidades con todos
sus atributos es ilegible a cualquier tamaño. La §1.1 da la forma completa del modelo; las
§1.2 a §1.4 entran al detalle por área. Entre las tres detalladas están las 20 tablas, sin
repetir ninguna.

En las cuatro **se omiten las aristas de `institution_id`**: las lleva *toda* tabla de dominio
y saturarían la figura. Van en la [§2](#2-aislamiento-multi-tenant), que es donde además
significan algo.

### 1.1 Mapa de relaciones

La forma completa del modelo, sin atributos. Es la figura para el informe cuando hace falta
enseñar el conjunto de un vistazo.

```mermaid
erDiagram
    institutions ||--o{ users : ""
    users ||--o| students : "perfil académico"
    users ||--o{ exams : "crea, SET NULL"
    users ||--o{ study_resources : "publica, SET NULL"
    users ||--o{ calendar_events : "SET NULL"

    students ||--o{ group_students : ""
    groups ||--o{ group_students : ""
    students ||--o{ student_subjects : ""
    subjects ||--o{ student_subjects : ""

    users ||--o{ teacher_assignments : "imparte"
    groups ||--o{ teacher_assignments : "CASCADE"
    subjects ||--o{ teacher_assignments : "CASCADE"

    subjects ||--o{ exams : "CASCADE"
    exams ||--o{ questions : "CASCADE"
    questions ||--o{ question_options : "CASCADE"
    exams ||--o{ exam_targets : "CASCADE"
    groups ||--o{ exam_targets : "CASCADE"

    students ||--o{ exam_attempts : "presenta"
    exams ||--o{ exam_attempts : "CASCADE"
    exam_attempts ||--o{ student_answers : "CASCADE"
    questions ||--o{ student_answers : ""
    student_answers ||--o{ student_answer_options : "CASCADE"
    question_options ||--o{ student_answer_options : ""

    students ||--o{ student_progress : "domina"
    subjects ||--o{ student_progress : ""
    students ||--o{ ai_recommendations : "recibe"
    students ||--o{ ai_chat_sessions : "conversa"
    exams ||--o{ ai_recommendations : "SET NULL"
    exams ||--o{ ai_chat_sessions : "SET NULL"
    exams ||--o{ calendar_events : "SET NULL"
    groups ||--o{ calendar_events : "SET NULL"
```

### 1.2 Personas y estructura académica

Quién es quién y cómo se agrupa. Las **tres** tablas pivote de aquí sostienen el ciclo
académico: la pertenencia a un grupo, la matrícula en materias y —desde el 08/08/2026— la
asignación del profesorado.

> **`teacher_assignments` es la pieza que hay que leer con atención.** Es la única fuente de
> la relación docente↔estudiante: «mis estudiantes» son los de los grupos que tengo
> asignados, y solo en las materias que imparto. Antes esa relación **no existía en el
> modelo** y el sistema la inferían de quién había creado cada examen, lo que permitía a un
> docente ampliarse el alcance solo. Ver §1.3 y la nota de decisiones al final de la §1.

```mermaid
erDiagram
    institutions ||--o{ users : "pertenece"
    users ||--o| students : "perfil académico"
    students ||--o{ group_students : "pertenece"
    groups ||--o{ group_students : "agrupa"
    students ||--o{ student_subjects : "matriculado"
    subjects ||--o{ student_subjects : "ofertada"
    users ||--o{ teacher_assignments : "docente imparte"
    groups ||--o{ teacher_assignments : "en el aula"
    subjects ||--o{ teacher_assignments : "la materia"

    institutions {
        uuid id PK
        string name
        string code UK
        jsonb settings "incl. passing_percentage"
        bool is_active
    }
    users {
        uuid id PK
        uuid institution_id FK "SET NULL, NULL solo en superadmin"
        string full_name
        string email UK
        string password_hash "bcrypt"
        enum user_type "superadmin admin teacher student"
        enum status "active inactive suspended"
    }
    students {
        uuid user_id PK, FK "PK y FK a users a la vez"
        string student_code "único por institución"
        int grade "derivado del aula"
        string section "derivado del aula"
        int year
        enum status "active inactive suspended"
        enum learning_style "visual auditivo lector"
        enum adecuacion_type "acceso contenido evaluacion"
        numeric overall_average
        int exams_completed_count
        timestamp last_activity_at
    }
    groups {
        uuid id PK
        string name
        int grade
        string section "A-D"
        string group_code "el «aula» de la carga masiva"
        int year "7A-2026 y 7A-2027 son filas distintas"
        int student_count "recalculado en cada alta y baja"
    }
    subjects {
        uuid id PK
        string name "único por institución, ignora mayúsculas"
    }
    group_students {
        uuid id PK
        uuid group_id FK
        uuid student_user_id FK
        timestamp joined_at
        timestamp left_at "baja lógica, conserva historial"
    }
    student_subjects {
        uuid id PK
        uuid student_user_id FK
        uuid subject_id FK
        timestamp enrolled_at
    }
    teacher_assignments {
        uuid id PK
        uuid teacher_user_id FK "users, no students"
        uuid group_id FK "CASCADE"
        uuid subject_id FK "CASCADE"
        timestamp assigned_at
    }
```

### 1.3 Evaluación

Del examen a la opción marcada. Es la cadena que cascadea entera al borrar una materia.

> **`created_by_teacher_id` es autoría, no permisos.** Registra quién redactó el examen y
> sirve para conservarlo cuando el docente se va (`SET NULL`). **No** decide a qué
> estudiantes ve ese docente: eso sale de `teacher_assignments` (§1.2). Hasta el 08/08/2026
> ambas cosas eran la misma, y era un problema — bastaba dirigir un borrador a un grupo
> ajeno para ganar acceso a su alumnado. Hoy `exam_targets` solo acepta grupos donde el
> autor tenga asignación **en la materia del examen**.

```mermaid
erDiagram
    subjects ||--o{ exams : "CASCADE"
    users ||--o{ exams : "autoría: created_by_teacher_id, SET NULL"
    exams ||--o{ questions : "CASCADE"
    questions ||--o{ question_options : "CASCADE"
    exams ||--o{ exam_targets : "CASCADE"
    groups ||--o{ exam_targets : "CASCADE"
    exams ||--o{ exam_attempts : "CASCADE"
    students ||--o{ exam_attempts : "presenta"
    exam_attempts ||--o{ student_answers : "CASCADE"
    questions ||--o{ student_answers : "respondida en"
    student_answers ||--o{ student_answer_options : "CASCADE"
    question_options ||--o{ student_answer_options : "marcada"

    exams {
        uuid id PK
        uuid subject_id FK "CASCADE"
        uuid created_by_teacher_id FK "nullable, SET NULL"
        string title
        int grade
        enum status "draft published active completed"
        int duration_minutes
        int max_attempts
        bool randomize_questions
        bool show_results_immediately
        bool allow_review_after_submission
        timestamp available_from
        timestamp available_until
    }
    questions {
        uuid id PK
        uuid exam_id FK
        text question_text
        enum question_type "multiple_choice true_false short_answer essay"
        int points
        text correct_answer_text "oculto al estudiante"
        int order_index
    }
    question_options {
        uuid id PK
        uuid question_id FK
        int option_index
        text option_text
        bool is_correct "oculto al estudiante"
    }
    exam_targets {
        uuid id PK
        uuid exam_id FK
        uuid group_id FK
    }
    exam_attempts {
        uuid id PK
        uuid exam_id FK
        uuid student_user_id FK
        int attempt_number
        timestamp started_at
        timestamp submitted_at
        timestamp paused_at
        int total_paused_seconds "descontado del deadline"
        numeric score
        numeric max_score
        enum grade_status "pending graded completed"
    }
    student_answers {
        uuid id PK
        uuid attempt_id FK
        uuid question_id FK
        text answer_text
        bool is_correct
        numeric points_awarded
        enum review_status "auto_graded needs_review reviewed"
        json correct_answer_snapshot
    }
    student_answer_options {
        uuid id PK
        uuid student_answer_id FK
        uuid option_id FK
    }
```

### 1.4 Seguimiento, tutor IA y apoyo

Lo que se deriva de la evaluación —el dominio por materia y las recomendaciones— más el
material de estudio y el calendario.

```mermaid
erDiagram
    students ||--o{ student_progress : "domina"
    subjects ||--o{ student_progress : "medido en"
    students ||--o{ ai_recommendations : "recibe"
    subjects ||--o{ ai_recommendations : "sobre"
    exams ||--o{ ai_recommendations : "SET NULL"
    students ||--o{ ai_chat_sessions : "conversa"
    subjects ||--o{ ai_chat_sessions : "SET NULL"
    exams ||--o{ ai_chat_sessions : "SET NULL"
    users ||--o{ study_resources : "publica, SET NULL"
    exams ||--o{ calendar_events : "SET NULL"
    groups ||--o{ calendar_events : "SET NULL"

    student_progress {
        uuid id PK
        uuid student_user_id FK
        uuid subject_id FK
        numeric mastery_percentage "AVG en SQL, no en PHP"
        timestamp reset_at "corte para repitentes"
    }
    ai_recommendations {
        uuid id PK
        uuid student_user_id FK
        uuid subject_id FK
        uuid exam_id FK "nullable, SET NULL"
        enum recommendation_type "strength weakness resource action"
        text recommendation_text
        json resource "URL de la lista blanca"
        timestamp generated_at "agrupa cada generación"
    }
    ai_chat_sessions {
        uuid id PK
        uuid student_user_id FK
        uuid subject_id FK "nullable"
        uuid exam_id FK "nullable"
        jsonb messages "recortado a los últimos 60"
        timestamp ended_at
    }
    study_resources {
        uuid id PK
        string title
        enum resource_type "video article exercise book pdf link other"
        string url "validado contra lista blanca"
        string difficulty "basic intermediate advanced"
        int grade_min
        int grade_max
        int estimated_duration
        string language
    }
    calendar_events {
        uuid id PK
        string title
        enum event_type "exam activity reminder meeting"
        timestamp start_at
        timestamp end_at
    }
```

### Seis decisiones del modelo que conviene saber leer

| Decisión | Dónde se ve | Por qué |
|---|---|---|
| **`students.user_id` es PK y FK a la vez** | `students` | Un estudiante *es* un usuario con perfil académico, no una entidad paralela. Las 6 relaciones del dominio estudiante apuntan a `students(user_id)`, no a `users(id)`: así la base rechaza un intento de examen a nombre de un docente |
| **`exams.created_by_teacher_id` es *nullable* con `SET NULL`** | `users → exams` | Permite dar de baja a un docente sin perder los exámenes que dejó al centro. Con `NOT NULL` habría sido imposible borrarlo |
| **`exams.subject_id` es `CASCADE`** | `subjects → exams` | Eliminar una materia elimina su contenido académico. Es destructivo a propósito y en cadena: materia → exámenes → preguntas → intentos → respuestas |
| **`group_students.left_at`** | `group_students` | La baja de un grupo es **lógica**: la fila se conserva como historial académico. Es el patrón que conviene extender a materias y grupos |
| **`teacher_assignments` existe como tabla, y no como inferencia** | `users → teacher_assignments → groups` | El permiso del docente es un **dato que crea el administrador**, no algo que se deduzca de lo que el docente hizo. Mientras se derivaba de la autoría del examen, el propio docente podía ampliarlo. La tabla nació vacía a propósito: al desplegar, nadie ve a nadie hasta que el admin asigne |
| **`students.student_code` es único *por institución*** | `students` | Es un identificador **interno de cada centro**, no de la plataforma: dos colegios pueden numerar «EST-0001» cada uno. La constraint era global hasta el 08/08/2026 y lo impedía |

---

## 2. Aislamiento multi-tenant

Cada fila del dominio lleva `institution_id`. **Las 19 columnas tienen clave foránea**, y
todas cascadean menos una.

```mermaid
flowchart TB
    INST["institutions<br/><i>raíz del tenant</i>"]

    subgraph CASCADE["18 tablas · ON DELETE CASCADE"]
        direction LR
        A["students · groups · subjects<br/>exams · questions · question_options"]
        B["exam_attempts · student_answers<br/>student_answer_options · student_progress"]
        C["ai_recommendations · ai_chat_sessions<br/>student_subjects · group_students · exam_targets"]
        D["study_resources · calendar_events · teacher_assignments"]
    end

    SETNULL["users<br/><b>ON DELETE SET NULL</b>"]

    INST -->|"borrar centro = borrar su contenido"| CASCADE
    INST -->|"dar de baja un centro<br/>NO borra a las personas"| SETNULL

    SCOPE["TenantScoped<br/><i>global scope de Eloquent</i>"]
    MW["SetTenantFromAuth<br/><i>middleware</i>"]

    MW -->|"inyecta institution_id<br/>del usuario autenticado"| SCOPE
    SCOPE -->|"filtra toda consulta<br/>y rellena la columna al crear"| CASCADE

    SUPER["superadmin<br/><i>institution_id = NULL</i>"]
    SUPER -.->|"nunca se vincula tenant_id:<br/>TenantScoped lo rechaza"| MW

    style SETNULL stroke-width:3px
    style INST stroke-width:3px
    style SUPER stroke-width:3px,stroke-dasharray: 5 5
```

**El aislamiento vive en dos capas, y esa redundancia es deliberada.** `TenantScoped` filtra
en la aplicación — si detecta contexto HTTP sin `institution_id`, lanza `RuntimeException` en
vez de devolver datos de más. Las claves foráneas lo respaldan en la base: sin ellas era
posible escribir un `institution_id` de una institución inexistente y romper el aislamiento
por debajo del ORM.

**El superadmin es la excepción, y por omisión en vez de por permiso.** Es el operador de la
plataforma —da de alta centros y a sus administradores— y no pertenece a ninguna institución:
su `institution_id` es `NULL`. Eso significa que `SetTenantFromAuth` nunca llega a vincular un
`tenant_id`, así que **toda** consulta a un modelo con `TenantScoped` falla para él. No es una
regla de autorización que un controlador nuevo pueda olvidarse de aplicar: aunque una ruta
académica se abriera a su rol por descuido, la consulta no devolvería datos igualmente.

Ese rol nació el 08/08/2026 para cerrar un fallo concreto: `GET /api/institutions` estaba
abierto a cualquier `admin` y **no filtraba por institución**, de modo que el administrador de
un centro listaba todos los centros del SaaS. Hoy el admin de institución no tiene ninguna
ruta hacia `/api/institutions`; gestiona lo suyo por `/api/system/config`.

---

## 3. Diagrama de clases

16 modelos Eloquent: las 15 entidades más `StudentSubject`. Los 3 pivotes restantes
(`group_students`, `exam_targets`, `student_answer_options`) se manipulan por *query builder*
y no tienen modelo.

Va en tres vistas por la misma razón que el ERD. La §3.1 incluye los *traits*, que son lo que
no se ve en el esquema y sostiene la seguridad por rol.

### 3.1 Personas, estructura y los traits de seguridad

```mermaid
classDiagram
    direction LR

    class TenantScoped {
        <<trait>>
        +bootTenantScoped()
        +filtra toda consulta por institution_id
        +rellena la columna al crear
    }
    class RevelaRespuestas {
        <<trait>>
        +expone is_correct a admin y docente
    }
    class AcotaExamenAlEstudiante {
        <<trait>>
        +recorta la configuracion del examen al alumno
    }
    class AcotaAlDocente {
        <<trait>>
        +gruposDelDocente()
        +materiasDelDocente()
        +estudiantesDelDocente()
        +docenteAlcanzaEstudiante()
        +docenteAlcanzaGrupoEnMateria()
    }

    class Institution {
        +uuid id
        +jsonb settings
        +users()
        +subjects()
        +groups()
        +studyResources()
    }
    class User {
        +uuid id
        +UserType user_type
        +UserStatus status
        +institution()
        +student()
        +studyResources()
    }
    class Student {
        +uuid user_id
        +LearningStyle learning_style
        +AdecuacionType adecuacion_type
        +numeric overall_average
        +user()
        +groups()
        +subjects()
        +attempts()
        +progress()
    }
    class Group {
        +uuid id
        +int year
        +int student_count
        +students()
        +exams()
    }
    class Subject {
        +uuid id
        +string name
        +institution()
        +exams()
    }
    class StudentSubject {
        +timestamp enrolled_at
        +student()
        +subject()
    }
    class TeacherAssignment {
        +timestamp assigned_at
        +teacher()
        +group()
        +subject()
    }

    TenantScoped <|.. Student
    TenantScoped <|.. Subject
    TenantScoped <|.. Group
    TenantScoped <|.. TeacherAssignment

    Institution "1" --> "0..*" User
    User "1" --> "0..1" Student
    Student "0..*" --> "0..*" Group : group_students
    Student "0..*" --> "0..*" Subject : student_subjects
    StudentSubject ..> Student
    StudentSubject ..> Subject

    User "1" --> "0..*" TeacherAssignment : docente
    TeacherAssignment ..> Group
    TeacherAssignment ..> Subject
    AcotaAlDocente ..> TeacherAssignment : unica fuente del alcance
```

**`AcotaAlDocente` es el trait que hay que mirar si se añade un endpoint.** Resuelve «mis
estudiantes» en un solo sitio, y existe precisamente porque la versión anterior de esa regla
estaba copiada a mano en seis controladores —y **faltaba en otros cuatro**, que por eso
servían el historial completo de cualquier alumno a cualquier docente—. Lo consumen
`StudentController`, `StudentProgressController`, `ReportController`, `AnalyticsController`,
`GroupController`, `StudentSubjectController`, `AiRecommendationController`, `ExamController`
y `ReportStrategyService`.

### 3.2 Evaluación

```mermaid
classDiagram
    direction LR

    class Exam {
        +ExamStatus status
        +int max_attempts
        +bool allow_review_after_submission
        +scopeVisibleTo(user)
        +syncGroups()
        +subject()
        +teacher()
        +questions()
        +attempts()
        +groups()
    }
    class Question {
        +QuestionType question_type
        +int points
        +hidden is_correct
        +hidden correct_answer_text
        +exam()
        +options()
        +answers()
    }
    class QuestionOption {
        +int option_index
        +bool is_correct
        +question()
    }
    class ExamAttempt {
        +int attempt_number
        +GradeStatus grade_status
        +int total_paused_seconds
        +percentage()
        +display_score()
        +exam()
        +student()
        +answers()
    }
    class StudentAnswer {
        +ReviewStatus review_status
        +numeric points_awarded
        +json correct_answer_snapshot
        +attempt()
        +question()
        +selectedOptions()
    }
    class TenantScoped {
        <<trait>>
    }
    class RevelaRespuestas {
        <<trait>>
    }
    class AcotaExamenAlEstudiante {
        <<trait>>
    }

    TenantScoped <|.. Exam
    RevelaRespuestas <|.. Question
    AcotaExamenAlEstudiante <|.. Exam

    Exam "1" --> "0..*" Question
    Question "1" --> "0..*" QuestionOption
    Exam "1" --> "0..*" ExamAttempt
    ExamAttempt "1" --> "0..*" StudentAnswer
    StudentAnswer "0..*" --> "0..*" QuestionOption : seleccionadas
```

### 3.3 Seguimiento, IA y apoyo

```mermaid
classDiagram
    direction LR

    class StudentProgress {
        +numeric mastery_percentage
        +timestamp reset_at
        +student()
        +subject()
    }
    class AiRecommendation {
        +AiRecommendationType recommendation_type
        +text recommendation_text
        +json resource
        +timestamp generated_at
        +student()
        +subject()
        +exam()
    }
    class AiChatSession {
        +jsonb messages
        +timestamp ended_at
        +student()
        +subject()
        +exam()
    }
    class StudyResource {
        +ResourceType resource_type
        +string url
        +string difficulty
        +int grade_min
        +int grade_max
        +institution()
        +creator()
    }
    class CalendarEvent {
        +CalendarEventType event_type
        +timestamp start_at
        +exam()
        +group()
    }
    class TenantScoped {
        <<trait>>
    }

    TenantScoped <|.. StudentProgress
    TenantScoped <|.. AiRecommendation
    TenantScoped <|.. AiChatSession
    TenantScoped <|.. StudyResource
    TenantScoped <|.. CalendarEvent
```

Los tres *traits* son lo que no se ve en el esquema y sostiene la seguridad por rol:
`RevelaRespuestas` y `AcotaExamenAlEstudiante` deciden **qué campos ve cada rol** sobre las
mismas filas, y `Exam::scopeVisibleTo()` centraliza qué exámenes puede ver un alumno —
activo, dentro de ventana y asignado a sus grupos.


---

## 4. Arquitectura del backend

API REST pura: **el backend no sirve HTML en ninguna ruta de la API**. La única capa de
presentación son las vistas Blade del correo y del formulario de contraseña.

```mermaid
flowchart TB
    CLIENT["SPA · React 19 + Vite + TypeScript"]

    subgraph EDGE["Borde"]
        TRAEFIK["Traefik<br/><i>TLS, proxy inverso</i>"]
    end

    subgraph APP["Contenedor Docker · DigitalOcean + Coolify"]
        OCTANE["FrankenPHP + Laravel Octane<br/><i>app en memoria, --max-requests=500</i>"]

        subgraph PIPE["Cadena de middleware, en orden"]
            direction TB
            M1["throttle:api<br/><i>120/min · rechaza antes de gastar</i>"]
            M2["SetTenantFromAuth<br/><i>fija institution_id</i>"]
            M3["SubstituteBindings<br/><i>route model binding ya con scope</i>"]
            M4["auth:sanctum<br/><i>token opaco, expira</i>"]
            M5["RequireRole<br/><i>admin · teacher · student</i>"]
            M1 --> M2 --> M3 --> M4 --> M5
        end

        CTRL["Controladores<br/><i>validan y traducen a HTTP</i>"]
        SVC["Servicios de dominio<br/><i>Grading · Progress · Reports · AI · BulkReassignment</i>"]
        ELO["Eloquent + TenantScoped"]
        SEC["SecurityHeaders<br/><i>en la respuesta</i>"]
    end

    WORKER["Worker de cola<br/><i>queue:work</i>"]
    SCHED["Scheduler<br/><i>schedule:run cada minuto</i>"]

    PG[("Supabase · PostgreSQL 17.6<br/><i>pooler en modo session, 5432</i>")]
    OPENAI["OpenAI · gpt-4o-mini"]
    SMTP["SMTP"]

    CLIENT -->|"JSON + Bearer token"| TRAEFIK
    TRAEFIK --> OCTANE
    OCTANE --> PIPE
    M5 --> CTRL --> SVC --> ELO --> PG
    CTRL --> SEC --> CLIENT

    SVC -->|"tutor y regeneración<br/>timeout 15 s"| OPENAI
    SVC -->|"encola correos"| PG
    WORKER -->|"lee jobs"| PG
    WORKER --> SMTP
    SCHED -->|"drena cola · purga tokens"| WORKER

    style M1 stroke-width:3px
    style PG stroke-width:3px
```

**Por qué el orden del middleware importa.** `throttle:api` va el primero a propósito: rechaza
el exceso *antes* de resolver tenant, autenticación o modelos, que es donde está el coste. Y
`SetTenantFromAuth` va antes de `SubstituteBindings` para que el *route model binding* ya
resuelva con el scope de institución activo — si no, un `{exam}` de otro centro se resolvería
y solo fallaría después.

**El cuello de botella real es el worker bloqueado.** Cada llamada a OpenAI retiene un worker
de Octane mientras dura, por eso el timeout está acotado a 15 s y hay un limitador global por
institución (`ai-global`, 120/min) además del límite por usuario. Análisis completo en
[`ANALISIS_CONCURRENCIA.md`](ANALISIS_CONCURRENCIA.md).

---

## 5. Flujo de autenticación

Hay **dos vías de alta**, y la diferencia no es cosmética: quién define la contraseña decide
si la cuenta nace utilizable.

```mermaid
sequenceDiagram
    autonumber
    actor Admin
    actor Alumno
    participant API as API
    participant Cola as Cola + worker
    participant DB as PostgreSQL

    rect rgb(240, 240, 240)
    note over Admin,DB: Alta masiva · la cuenta nace inactiva
    Admin->>API: POST /students/bulk-upload (CSV/XLSX)
    API->>DB: User(status=inactive, contraseña aleatoria) + Student
    API->>DB: token hasheado en password_reset_tokens
    API->>Cola: PasswordSetupMail (fuera de la transacción)
    Cola-->>Alumno: correo "activá tu cuenta"
    end

    Alumno->>API: GET /password/reset/{token} (formulario Blade)
    Alumno->>API: POST /api/password/reset
    API->>DB: guarda hash · status = active · consume token · revoca sesiones

    rect rgb(240, 240, 240)
    note over Admin,DB: Alta individual · el admin entrega la contraseña en mano
    Admin->>API: POST /register (solo admin)
    API->>DB: User(status=active) — no se envía correo
    end

    Alumno->>API: POST /auth/login
    API->>DB: Hash::check contra password_hash
    alt credenciales inválidas
        API-->>Alumno: 401
    else status != active
        API-->>Alumno: 403 "Usuario inactivo o suspendido"
    else
        API->>DB: crea token en personal_access_tokens
        API-->>Alumno: 200 + token opaco (Sanctum, expira)
    end
```

**Tres reglas que sostienen el flujo:**

- `/password/forgot` responde a cuentas `active` **e** `inactive`, nunca a `suspended`. Sin
  eso, quien perdiera el correo de alta quedaba bloqueado para siempre; y una cuenta suspendida
  la bloqueó un administrador a propósito.
- El correo se elige según el estado: a quien nunca tuvo contraseña no se le habla de
  *recuperarla*.
- **Solo se activa desde `inactive`.** Un reset sobre una cuenta `suspended` cambia la
  contraseña pero no la reactiva.

> ⚠️ Los tokens son **opacos** contra `personal_access_tokens`, no JWT. El informe dice JWT en
> dos sitios; es una de las correcciones de `ANALISIS_MODELO_DATOS_TFG.md` §9.1.

---

## 6. Flujo de examen

### 6.1 Estados del examen

```mermaid
stateDiagram-v2
    [*] --> draft : POST /exams
    draft --> published : PATCH status
    published --> active : PATCH status, rechaza si available_until ya pasó
    active --> completed : PATCH status
    draft --> [*] : DELETE, solo permitido en draft

    note right of draft
        Editable en draft y published.
        En active o completed devuelve 409.
    end note
    note right of active
        Único estado en el que el alumno
        lo ve y puede presentarlo.
    end note
```

### 6.2 Ciclo de vida del intento

```mermaid
sequenceDiagram
    autonumber
    actor Alumno
    participant API as ExamAttemptController
    participant Reglas as ExamAttemptRulesService
    participant Cal as ExamGradingService
    participant Prog as StudentProgressService
    participant IA as AiRecommendationService
    participant DB as PostgreSQL

    Alumno->>API: POST /exams/{exam}/attempts/start
    API->>Reglas: assertExamIsStartable — activo y en ventana
    API->>DB: BEGIN · SELECT students FOR UPDATE
    note right of DB: el lock serializa dobles clics<br/>del mismo alumno
    API->>Reglas: assertAttemptsAvailable — max_attempts
    API->>DB: INSERT exam_attempt (attempt_number, max_score) · COMMIT
    API-->>Alumno: 201

    opt Pausa
        Alumno->>API: PATCH .../pause
        API->>DB: paused_at = now — bloquea el submit
        Alumno->>API: PATCH .../resume
        API->>DB: total_paused_seconds += pausa
    end

    Alumno->>API: POST .../submit (answers[])
    API->>Reglas: assertAttemptIsSubmittable
    note right of Reglas: deadline = duration_minutes<br/>− tiempo pausado + 30 s de gracia<br/>× adecuación: acceso 1,25 · evaluación 1,50
    API->>API: valida forma por tipo de pregunta

    rect rgb(240, 240, 240)
    note over API,DB: Todo dentro de una transacción
    API->>Cal: gradeAttempt
    Cal->>DB: 2 INSERT por lotes — coste plano, 22 queries
    note right of Cal: MC y TF se autocalifican<br/>SA y Essay quedan needs_review
    API->>Prog: recalcFromAttempts — AVG en SQL, respeta reset_at
    API->>IA: generateFromAttempt
    note right of IA: plantillas por porcentaje,<br/>SIN llamada a OpenAI
    end

    API-->>Alumno: score, percentage, progreso y recomendaciones

    opt El alumno quiere análisis con IA
        Alumno->>API: POST /exam-attempts/{id}/recommendations/regenerate
        API->>IA: regenerateForAttempt — aquí sí llama a OpenAI
        note right of IA: cupo: la del submit + 3,<br/>por intento
    end
```

> ⚠️ **La recomendación del submit no la escribe la IA.** `generateFromAttempt` es un
> `if/elseif/else` sobre el porcentaje con textos fijos; OpenAI solo interviene si el alumno
> pide regenerar. Es deliberado —una llamada de segundos dentro de la transacción ataría un
> worker justo en el pico de concurrencia, cuando todos entregan a la vez—, pero **el informe
> lo describe al revés**: ver `ESTADO_Y_PENDIENTES.md` §3.6 nº 1.

---

## 7. Flujo del tutor IA

```mermaid
sequenceDiagram
    autonumber
    actor Alumno
    participant Ctrl as AiTutorController
    participant Svc as AiTutorService
    participant Cache as Caché · TTL 5 min
    participant OA as OpenAI · gpt-4o-mini
    participant Val as AiOutputValidator
    participant DB as PostgreSQL

    Alumno->>Ctrl: POST /ai/tutor/chat<br/>{message, session_id?, subject_id?, exam_id?, mode}
    Ctrl->>Ctrl: role:student + perfil Student existe
    Ctrl->>DB: valida subject_id y exam_id dentro de su tenant
    note right of Ctrl: el examen vale si lo rindió<br/>o si hoy lo tiene disponible

    Ctrl->>Svc: chat(...)
    Svc->>Cache: system prompt del estudiante
    alt caché fría
        Svc->>DB: perfil + progreso por materia
        Svc->>Cache: guarda 5 min
    end
    note right of Svc: viajan grado, estilo de aprendizaje<br/>y % de dominio.<br/><b>NUNCA el nombre</b>

    Svc->>DB: resuelve o crea ai_chat_session
    Svc->>Svc: últimos 20 mensajes + prefijo de modo
    Svc->>OA: chat completion (600 tokens, timeout 15 s)

    OA-->>Svc: respuesta
    Svc->>Val: validate + sanitize
    alt PII detectada o longitud fuera de rango
        Val-->>Svc: bloqueado → mensaje de reserva
    else enlace fuera de la lista blanca
        Val-->>Svc: el enlace se sustituye por un aviso
    end

    Svc->>DB: append incremental al JSONB<br/><i>solo el delta, recorta a 60</i>
    Svc-->>Alumno: {session_id, reply, message_count}
```

### La frontera de privacidad, que es lo que más se pregunta

```mermaid
flowchart LR
    CHAT["ai_chat_sessions<br/><i>la conversación</i>"]
    RECS["ai_recommendations<br/><i>recomendaciones estructuradas</i>"]
    METRICS["Métricas de uso<br/><i>agregadas</i>"]
    RANK["Ranking nominal<br/><i>quién usa más el tutor</i>"]

    ALUMNO(["Estudiante"])
    DOCENTE(["Docente"])
    ADMIN(["Admin"])

    CHAT --> ALUMNO
    CHAT -.->|"NUNCA, en ningún reporte"| DOCENTE
    RECS --> ALUMNO
    RECS -->|"solo las de exámenes que él creó"| DOCENTE
    RECS -->|"toda su institución"| ADMIN
    METRICS --> DOCENTE
    RANK -.->|"no: identifica menores por su uso"| DOCENTE
    RANK --> ADMIN

    style CHAT stroke-width:3px
```

**La frontera no es alumno/docente, sino conversación/recomendación.** El informe se
contradice en este punto —promete que el docente no verá mensajes individuales y a la vez le
pide seguimiento pedagógico—, y esta lectura resuelve las dos cosas: el docente nunca ve un
mensaje del chat, y el seguimiento se apoya en las recomendaciones, que es el artefacto que
el propio informe llama *pedagógico*. Fijado como prueba en
`TutorStrategiesTest::test_chat_history_never_appears_in_the_strategies_report`.

---

## 8. Diagrama de casos de uso

```mermaid
flowchart LR
    SUPER(["Superadmin<br/><i>externo a los centros</i>"])
    ADMIN(["Admin de institución"])
    DOCENTE(["Docente"])
    ALUMNO(["Estudiante"])

    subgraph PLATAFORMA["Plataforma · solo superadmin"]
        S1["Alta y baja de instituciones"]
        S2["Gestionar administradores<br/>de cada institución"]
    end

    subgraph GESTION["Gestión institucional · solo admin"]
        U1["Configurar su institución<br/><i>/system/config</i>"]
        U2["Dar de alta usuarios<br/>individual y masiva"]
        U3["Administrar catálogo de materias"]
        U4["Crear grupos y matricular<br/><i>membresía admin-only</i>"]
        U5["Promoción de fin de año<br/><i>reasignación masiva</i>"]
        U21["Asignar docentes a<br/>grupo y materia"]
    end

    subgraph DOCENCIA["Docencia · admin y docente"]
        U6["Crear y publicar exámenes"]
        U7["Gestionar preguntas"]
        U8["Asignar exámenes a sus grupos"]
        U10["Calificar preguntas abiertas"]
        U11["Ver analíticas y reportes"]
        U12["Descargar estrategias del tutor"]
        U13["Publicar recursos de estudio"]
    end

    subgraph ESTUDIO["Estudio · solo estudiante"]
        U14["Ver exámenes disponibles"]
        U15["Presentar examen"]
        U16["Consultar sus resultados"]
        U17["Conversar con el tutor IA"]
        U18["Ver su diagnóstico"]
        U19["Ver sus recomendaciones"]
        U20["Consultar su progreso"]
    end

    COMUN["Iniciar sesión · recuperar contraseña · ver calendario"]

    SUPER --> PLATAFORMA
    ADMIN --> GESTION
    ADMIN --> DOCENCIA
    DOCENTE --> DOCENCIA
    ALUMNO --> ESTUDIO
    SUPER --> COMUN
    ADMIN --> COMUN
    DOCENTE --> COMUN
    ALUMNO --> COMUN

    U11 -.->|"acotado a sus grupos asignados"| DOCENTE
    U12 -.->|"sin el historial de chat"| DOCENTE
    U8 -.->|"403 fuera de su asignación"| DOCENTE
    U21 -.->|"habilita todo lo del docente"| DOCENTE

    style SUPER stroke-width:3px
```

**Tres precisiones que el informe no recoge:**

- El enum `UserType` tiene **cuatro** roles, no tres, y el cuarto es **`superadmin`**, no el
  `parent` que figuraba antes: ese se retiró el 08/08/2026 tras comprobar que no tenía rutas
  ni una sola fila real. El superadmin es **externo a las instituciones** y su alcance se
  agota en dos CRUD: instituciones y sus administradores.
- El docente no es un admin recortado, **y su alcance no se lo da él**. Lo fija el
  administrador en `teacher_assignments`; fuera de sus grupos asignados no ve nada — ni
  estudiantes, ni resultados, ni recomendaciones, ni estrategias— y ni siquiera puede dirigir
  un examen a un grupo ajeno. La membresía de los grupos también es admin-only, para que no
  pueda ampliarse el alcance metiendo alumnos en un grupo suyo.
- El administrador de institución **no ve el catálogo de instituciones**. Su propio centro lo
  configura por `/api/system/config`.

---

## 9. Diagrama de proceso: el ciclo académico

El proceso completo de un curso, desde la matrícula hasta la promoción del año siguiente.

```mermaid
flowchart TB
    START(["Inicio de curso"])

    A["Admin crea las aulas del año<br/><i>llevan year: 7A-2026 ≠ 7A-2027</i>"]
    A2["Admin asigna docentes<br/><i>teacher_assignments: grupo + materia</i>"]
    B["Alta de estudiantes<br/><i>la carga masiva exige columna «aula»<br/>y matricula en group_students</i>"]
    C["Alumno activa su cuenta<br/><i>define contraseña</i>"]
    D["Matrícula en materias<br/><i>student_subjects</i>"]

    E["Docente crea examen diagnóstico<br/><i>draft</i>"]
    F["Añade preguntas y opciones"]
    G["Asigna a sus grupos<br/><i>exam_targets · 403 si no los tiene</i>"]
    H["Publica y activa"]

    I["Alumno presenta el examen"]
    J["Corrección automática<br/><i>MC y TF</i>"]
    K{"¿Hay preguntas<br/>abiertas?"}
    L["Docente califica a mano<br/><i>needs_review → reviewed</i>"]
    M["Recalcular dominio por materia<br/><i>student_progress</i>"]
    N["Recomendaciones del tutor"]

    O["Reportes y analíticas<br/><i>agregados en un solo SELECT</i>"]
    P["Alumno consulta al tutor IA"]

    Q{"Fin de curso:<br/>¿promovido?"}
    R["Reasignar al grupo<br/>del grado siguiente"]
    S["Reasignar al mismo grado<br/>del año nuevo"]
    T["Resetear progreso<br/><i>marca reset_at</i>"]
    END(["Curso siguiente"])

    START --> A --> A2 --> B --> C --> D
    D --> E --> F --> G --> H
    H --> I --> J --> K
    K -->|sí| L --> M
    K -->|no| M
    M --> N --> O
    N --> P
    P --> O
    O --> Q
    Q -->|promovido| R --> END
    Q -->|repitente| S --> T --> END

    style Q stroke-width:3px
    style T stroke-width:3px
    style A2 stroke-width:3px
```

**Los dos primeros pasos son nuevos y bloquean todo lo demás.** Sin `teacher_assignments` el
docente no ve a ningún estudiante —la tabla nace vacía a propósito—, y sin la columna `aula`
en la carga masiva el alumno queda con etiqueta de sección pero sin matrícula, que en la
práctica lo hace invisible: no lo ve ningún docente, no recibe exámenes y no sale en informes.
El aula tiene que existir antes: el archivo **no crea grupos**, para que un typo en el código
no genere un aula fantasma con un alumno dentro.

Un matiz del traslado: volver a subir la fila de un estudiante con otra aula lo **mueve**,
cerrando la matrícula anterior con `left_at` en vez de borrarla. Es la única vía por la que un
alumno cambia de aula, y cambia también qué docente pasa a ver su expediente — por eso la
respuesta del bulk informa de `reasignados` aparte de `matriculados`.

**El paso que menos se ve y más importa es el reseteo.** `recalcFromAttempts` promedia *todos*
los intentos enviados y se dispara en cada entrega y en cada revisión de respuesta, así que
poner el progreso a cero sin más se desharía solo en el siguiente examen. Por eso
`student_progress.reset_at` marca un **corte temporal** que el recálculo respeta: los intentos
anteriores dejan de contar para el dominio actual, pero **no se borran** — el historial
académico queda íntegro.

El orden de la promoción también importa: primero se mueve a los repitentes por lista
explícita, y después al resto con `from_group_id`, que a esas alturas ya solo devuelve a los
promovidos. Así no hace falta ningún parámetro de exclusión.

---

## Qué figuras del informe cubre cada diagrama

| Figura del informe | Aquí | Estado |
|---|---|---|
| — *(no existe)* | §1 Modelo entidad-relación | **Nueva.** El informe no tiene ERD pese a tener 20 tablas y 51 FK |
| — *(no existe)* | §2 Aislamiento multi-tenant | **Nueva.** Es la decisión de arquitectura más transversal del sistema, y ahora incluye la frontera superadmin/institución |
| Figura 2 · Diagrama de clases | §3 | Rehacer: faltan `AiChatSession`, `StudentSubject` y `TeacherAssignment` |
| Figura 7 y 8 · Arquitectura | §4 | Rehacer: el informe describe Node.js y Next.js, que no existen |
| Figura 6 · Flujo de autenticación | §5 | Rehacer: es Sanctum con tokens opacos, no JWT |
| Figura 4 · Flujo de examen | §6 | Rehacer: añadir pausa/reanudación y adecuación curricular |
| Figura 10 · Flujo de ChatBot | §7 | Rehacer: añadir modos, validación de salida y frontera de privacidad |
| Figura 9 · Casos de uso | §8 | Rehacer: son cuatro roles, no tres, y el cuarto es `superadmin` — externo a los centros — no el `parent` retirado |
| Figura 3 · Diagrama de proceso | §9 | Rehacer: añadir el ciclo completo con repitentes, la asignación de docentes y el aula obligatoria en la carga masiva |
