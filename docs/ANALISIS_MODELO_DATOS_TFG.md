# Modelo de datos — el sistema construido y qué corregir en el informe

**Fecha:** 3 de agosto de 2026 · revisado el 5 y el **8 de agosto de 2026**
**Estado:** modelo de datos **cerrado** en código y en producción; **todo lo pendiente son correcciones al informe**

> ## Criterio del documento
>
> **Manda el sistema construido. El informe se ajusta a él.**
>
> Este documento nació comparando la base de datos contra `schema de la base de datos.sql`, el esquema que se dibujó al principio del TFG, y tratándolo como *la referencia*. Esa lectura se cambia el 08/08/2026: aquel fichero es un **diseño preliminar**, no una autoridad sobre el entregable. El sistema que se defiende es el que corre en producción, y el informe es el que debe describirlo.
>
> Eso no invalida lo hecho hasta aquí. La comparación fue útil por sí misma: destapó cinco operaciones rotas en producción que ninguna prueba cubría (§5), y en esos casos el diseño preliminar tenía razón — se adoptó porque era mejor, no por ser anterior. Lo que cambia es el **criterio para lo que quedaba en duda**: ya no hay que elegir entre «coherencia del modelo» y «fidelidad al documento de referencia». Gana siempre la coherencia, y el documento se reescribe (§7).
>
> **Consecuencia práctica:** en este documento la palabra *divergencia* describe el hallazgo histórico, no un defecto pendiente. Nada del modelo de datos está esperando decisión (§7). Lo accionable son dos secciones, ambas sobre el documento:
>
> - **§9 — qué cambiar en el informe**, con la corrección concreta de cada punto.
> - **§10 — las contradicciones, por escrito**: dónde el informe se contradice a sí mismo (§10.1) y dónde contradice al sistema (§10.2), con cita y párrafo, para que el equipo entre a modificar sin volver a buscarlas. **§10.3 avisa de que los números de párrafo de la §9 se quedaron desfasados** y da las equivalencias.

---

## 1. Alcance y fuentes

| Rol | Artefacto |
|---|---|
| **Fuente de verdad — el sistema entregado** | `database/sql/01_schema.sql`, artefacto generado con `pg_dump` desde las migraciones, y la base de Supabase de la que sale |
| **Diseño preliminar (histórico)** | `schema de la base de datos.sql` — el modelo relacional que se dibujó al inicio del TFG. Ya no está en el árbol de trabajo; se conserva en el historial de git |
| **Documento a corregir** | `CTFG-DOC-18_Guia_para_Informe_Final_TFG 2025.docx` |
| **Producción** | Supabase `aws-0-us-west-2`, PostgreSQL 17.6 |

Se comparan **claves foráneas**: tabla origen, columna, tabla/columna destino y comportamiento `ON DELETE`. No se comparan tipos de columna, índices ni constraints CHECK (auditados por separado en E10/E11).

### 1.1 Estado final del modelo (08/08/2026)

Lo que hay que llevar al capítulo de modelo de datos del informe:

| Magnitud | Valor |
|---|---|
| Claves foráneas | **47** |
| Tablas de dominio | **19** (15 entidades + 4 pivotes) |
| Tablas de framework | 5 (`migrations`, `jobs`, `failed_jobs`, `password_reset_tokens`, `personal_access_tokens`) |
| Columnas `institution_id` | **18, todas con FK** — 17 en `CASCADE` y 1 en `SET NULL` (`users`, deliberado: dar de baja un centro no borra a las personas) |

Pivotes: `group_students`, `exam_targets`, `student_answer_options`, `student_subjects`. De las 19 tablas de dominio, 16 tienen modelo Eloquent (las 15 entidades más `StudentSubject`).

Comprobable en cualquier momento:

```bash
grep -c "ADD CONSTRAINT .* FOREIGN KEY" database/sql/01_schema.sql   # 47
grep -c "^CREATE TABLE public\."        database/sql/01_schema.sql   # 24 (19 + 5)
```

## 2. Método (reproducible)

La comparación se hizo con un script que extrae las FK de ambos esquemas y las cruza, en vez de revisarlas a ojo. Las dos sintaxis difieren: el TFG usa `REFERENCES` inline en el `CREATE TABLE`; `pg_dump` emite `ALTER TABLE ... ADD CONSTRAINT`.

```python
# Referencia TFG: REFERENCES inline
re.match(r'\s*([a-z_]+)\s+[a-z]+.*?REFERENCES\s+([a-z_]+)\s*\(([a-z_]+)\)(.*)', linea)

# Artefacto generado: ALTER TABLE
re.compile(r'ALTER TABLE ONLY public\.([a-z_]+)\s*\n\s*ADD CONSTRAINT (\S+) '
           r'FOREIGN KEY \(([a-z_]+)\)\s*REFERENCES public\.([a-z_]+)\(([a-z_]+)\)([^;]*);')
```

Clave de comparación: `(tabla, columna)` → `(tabla_destino, columna_destino, on_delete)`.

**Resultado inicial: 36 FK en el TFG, 42 en la implementación, 33 divergencias.**

Las conclusiones sobre comportamiento **no se dedujeron del esquema**: se verificaron ejecutando los endpoints contra una base real (§5).

---

## 3. Qué encontró la comparación (histórico)

> Los cuatro grupos de abajo son el **hallazgo de agosto de 2026**, no una lista de
> pendientes: los grupos A, B y C se resolvieron en la migración de §6 y el grupo D
> es material para el informe (§9.3). Se conservan porque el método y la evidencia
> son lo aprovechable para el capítulo de modelo de datos — «lo que el análisis
> destapó», no «lo que falta por hacer».

### 3.1 Grupo A — La relación apunta a otra tabla (4)

| Tabla.columna | Diseño preliminar | Implementación (entonces) |
|---|---|---|
| `exam_attempts.student_user_id` | `students(user_id)` | `users(id)` |
| `student_progress.student_user_id` | `students(user_id)` | `users(id)` |
| `ai_recommendations.student_user_id` | `students(user_id)` | `users(id)` |
| `group_students.student_user_id` | `students(user_id)` | `users(id)` |

**Significado.** `students.user_id` es a la vez PK de `students` y FK a `users`. Apuntar a `users(id)` admite cualquier usuario del sistema; apuntar a `students(user_id)` exige que además tenga perfil de estudiante. Aquí **el diseño preliminar tenía razón**: son relaciones del dominio *estudiante*, no *usuario*, y se adoptó por eso, no por respetar el documento.

**Consecuencia comprobada:** se pudo crear un intento de examen a nombre de un **docente**. La restricción más estricta lo rechaza en la base.

### 3.2 Grupo B — Mismo destino, distinto `ON DELETE` (28)

| Tabla.columna | TFG | Implementación |
|---|---|---|
| `ai_recommendations.exam_id` | SET NULL | NO ACTION |
| `ai_recommendations.subject_id` | SET NULL | NO ACTION |
| `calendar_events.created_by` | SET NULL | NO ACTION |
| `calendar_events.exam_id` | SET NULL | NO ACTION |
| `calendar_events.group_id` | SET NULL | NO ACTION |
| `calendar_events.institution_id` | CASCADE | NO ACTION |
| `exam_attempts.exam_id` | CASCADE | NO ACTION |
| `exam_targets.exam_id` | CASCADE | NO ACTION |
| `exam_targets.group_id` | CASCADE | NO ACTION |
| `exam_targets.institution_id` | CASCADE | NO ACTION |
| `exams.institution_id` | CASCADE | NO ACTION |
| `group_students.group_id` | CASCADE | NO ACTION |
| `groups.institution_id` | CASCADE | NO ACTION |
| `question_options.question_id` | CASCADE | NO ACTION |
| `questions.exam_id` | CASCADE | NO ACTION |
| `questions.institution_id` | CASCADE | NO ACTION |
| `student_answer_options.institution_id` | CASCADE | NO ACTION |
| `student_answer_options.option_id` | CASCADE | NO ACTION |
| `student_answer_options.student_answer_id` | CASCADE | NO ACTION |
| `student_answers.attempt_id` | CASCADE | NO ACTION |
| `student_answers.question_id` | CASCADE | NO ACTION |
| `student_progress.subject_id` | CASCADE | NO ACTION |
| `students.user_id` | CASCADE | NO ACTION |
| `study_resources.institution_id` | CASCADE | NO ACTION |
| `subjects.institution_id` | CASCADE | NO ACTION |
| `users.institution_id` | SET NULL | NO ACTION |
| **`exams.created_by_teacher_id`** | NO ACTION | **SET NULL** ← deliberada |
| **`exams.subject_id`** | NO ACTION | **CASCADE** ← deliberada |

**Significado.** No es cosmético. `NO ACTION` es el comportamiento por defecto de `$table->foreign()` en Laravel: **bloquea** el borrado del padre si hay hijos. Como las cascadas del TFG se cortaban en la primera tabla hija, borrados enteros fallaban (§5).

### 3.3 Grupo C — FK ausentes en la implementación (3)

| Tabla.columna | TFG | Implementación |
|---|---|---|
| `ai_recommendations.institution_id` | `institutions(id)` CASCADE | *sin constraint* |
| `question_options.institution_id` | `institutions(id)` CASCADE | *sin constraint* |
| `student_answers.institution_id` | `institutions(id)` CASCADE | *sin constraint* |

La columna existía pero sin restricción: era posible escribir un `institution_id` de una institución inexistente, rompiendo el aislamiento multi-tenant desde la capa de datos.

### 3.4 Grupo D — Relaciones que el informe no documenta (9)

| Tabla.columna | Destino | Motivo |
|---|---|---|
| `ai_chat_sessions.institution_id` | `institutions(id)` | Tutor IA conversacional, añadido tras el diseño original |
| `ai_chat_sessions.student_user_id` | `students(user_id)` | ídem |
| `ai_chat_sessions.subject_id` | `subjects(id)` | ídem |
| `ai_chat_sessions.exam_id` | `exams(id)` | ídem. ⚠️ **Estaba muerta**: existía en tabla, modelo y relación Eloquent, pero ninguna ruta la escribía — siempre NULL. Cableada el 08/08/2026 (`POST /ai/tutor/chat` acepta `exam_id`), para no documentar en el ERD una relación que no ocurre |
| `student_subjects.institution_id` | `institutions(id)` | Matrícula estudiante↔materia, añadida después |
| `student_subjects.student_user_id` | `students(user_id)` | ídem |
| `student_subjects.subject_id` | `subjects(id)` | ídem |
| `students.institution_id` | `institutions(id)` | Refuerzo de tenant no previsto en el diseño |
| `group_students.institution_id` | `institutions(id)` | ídem |

**Aquí el desalineado es el informe, no el código.** Son funcionalidades reales del sistema entregado.

---

## 4. Decisiones de diseño que se apartan del boceto inicial

Dos casos en los que el sistema entregado mejora el diseño preliminar, con la decisión tomada y documentada en la migración `fix_exam_cascade_constraints` (09/05/2026). **No son desviaciones que haya que justificar ante nadie**: son las reglas del modelo entregado, y el informe debe describirlas como tales.

| Relación | Boceto inicial | Sistema entregado | Justificación |
|---|---|---|---|
| `exams.created_by_teacher_id` | `NOT NULL`, NO ACTION | *nullable*, **SET NULL** | Permite dar de baja a un docente sin perder los exámenes que creó. Con el diseño original, borrar un profesor sería imposible mientras tuviera exámenes |
| `exams.subject_id` | NO ACTION | **CASCADE** | Coherencia con la regla de negocio de que eliminar una materia elimina su contenido académico |

**Para el informe:** conviene justificarlas en el capítulo de modelo de datos en vez de presentarlas como desviaciones.

---

## 5. Evidencia empírica

Las divergencias del grupo B no se dedujeron: se comprobó su efecto real montando la cadena completa (materia → examen → preguntas → opciones → grupo con alumno → asignación → intento → respuesta → opción marcada → progreso) y llamando a los endpoints.

**Estado ANTES de la alineación:**

```
DELETE /api/subjects/{id}   (con examen+preguntas+intentos)  -> HTTP 500
DELETE /api/groups/{id}     (con miembros + examen asignado) -> HTTP 500
DELETE /api/exams/{id}      (con preguntas + intentos)       -> HTTP 500
DELETE /api/questions/{id}  (con opciones + respuestas)      -> HTTP 409 (*)
DELETE /api/users/{id}      (alumno con toda su actividad)   -> HTTP 500

filas que sobreviven tras DELETE /subjects: TODAS
```

(*) El 409 de `/questions` es una regla de negocio del controlador («no se puede eliminar la última pregunta del examen»), no un fallo de FK. Con dos o más preguntas caía en el mismo 500. Su comentario en código —*"opciones eliminadas en cascada por DB"*— era falso.

**Por qué nadie lo detectó.** Los tests de borrado existentes (`test_delete_subject`, `test_delete_group`, `test_delete_exam`) eliminaban entidades **vacías**, sin contenido asociado. Nunca ejercitaron la cascada. `ESTADO_Y_PENDIENTES.md` afirmaba desde el 09/05/2026 que esos borrados cascadeaban.

**Segunda comprobación (grupo A):** creación de un `exam_attempt` con `student_user_id` = un usuario docente → **aceptado** por la base.

**Tercera, del 08/08/2026 — quedaba una quinta operación rota, y la referencia del TFG no la cubría.** `DELETE /api/users/{id}` seguía devolviendo **500** con cualquier docente que hubiera subido un recurso de estudio: `study_resources.created_by` era la última FK a `users(id)` en `NO ACTION`. No apareció en §3 porque **el esquema de referencia la declara igual de laxa**: ambas partes coincidían en el mismo hueco, así que la comparación no la marcó como divergencia. Es un matiz aprovechable para el informe — muestra el límite del método: una comparación automatizada encuentra desacuerdos, no errores compartidos. Corregida a `SET NULL`, coherente con las otras dos FK de autoría (`exams.created_by_teacher_id` y `calendar_events.created_by`), con test que se verificó que falla sin el arreglo.

---

## 6. Alineación aplicada

Migración `2026_08_03_000001_align_fk_constraints_with_tfg_model.php`.

**33 constraints corregidas** = 4 (grupo A) + 26 (grupo B, excluyendo las 2 deliberadas) + 3 (grupo C).

Salvaguardas de la migración:

- **Detección previa de huérfanos.** Las 7 FK que se vuelven más estrictas se comprueban antes de tocar el esquema; si hubiera filas que las violan, aborta con el detalle en vez de fallar a media migración. En producción: **0 huérfanos** en las 7 comprobaciones.
- **`down()` real.** Restaura las 30 constraints preexistentes a su forma anterior y elimina las 3 nuevas. Probada la secuencia `up → down → up` en base limpia.
- **Generación programática.** El listado de constraints se generó desde la comparación, no transcribiendo a mano.

**Cobertura nueva:** `tests/Feature/Db/CascadeIntegrityTest.php`, que montan la cadena completa antes de borrar y comprueban tanto lo que debe desaparecer como lo que debe sobrevivir. Empezó con 8 casos; hoy son **12**, tras los añadidos del 08/08/2026 (§5 y §7.2).

**Verificación en producción tras aplicar:** 6 FK apuntando a `students(user_id)`; datos intactos (65 estudiantes, 206 materias, 73 usuarios, 3 intentos).

✅ **Las migraciones del 08/08/2026 —`study_resources_created_by_set_null` y `complete_tenant_fk_coherence`— están aplicadas en producción** (08/08/2026), y `01_schema.sql` se regeneró con `schema:dump-sql` desde la base real.

Verificación posterior, consultada contra `pg_constraint`:

- **47 FK**, las 5 tocadas con el `ON DELETE` esperado.
- De las 18 columnas `institution_id`, **la única que no es `CASCADE` es `users` (`SET NULL`)**, que es lo deliberado.
- Datos intactos: 2 instituciones, 73 usuarios, 65 estudiantes, 206 materias, 3 exámenes, 3 intentos, 6 filas de progreso, 4 recursos, 5 membresías — idénticos antes y después.
- **`ENABLE ROW LEVEL SECURITY` conservado en las 24 tablas.** Era el riesgo real de regenerar el artefacto, y el dump sale de Supabase, así que lo trae.
- 0 huérfanos en las dos comprobaciones previas de la migración.

---

## 7. Lo que quedaba abierto, y cómo se cerró

Las tres decisiones que este documento dejaba pendientes se resolvieron el 08/08/2026 al fijar el criterio de la cabecera: **manda el sistema, el informe se ajusta.** Ninguna sigue abierta del lado del modelo de datos.

### 7.1 Las 9 relaciones que el informe no documenta → **acción sobre el informe**

El capítulo de modelo de datos debe incluir `ai_chat_sessions` y `student_subjects` como entidades, y las 2 decisiones de §4 como lo que son: reglas del modelo. **El informe es el entregable evaluable**, así que este punto no es opcional. Detalle en §9.3.

### 7.2 Las dos últimas columnas `institution_id` sin FK → **cerrado en código (08/08/2026)**

`exam_attempts.institution_id` y `student_progress.institution_id` eran las dos únicas columnas de tenant sin restricción, y a ellas se sumaban `students.institution_id` y `group_students.institution_id` en `NO ACTION` mientras el resto cascadeaba. Los cuatro casos venían de lo mismo: la migración de §6 se ciñó a lo que declaraba el boceto inicial, y el boceto no los cubría.

La duda estaba planteada como **«coherencia del modelo vs. fidelidad al documento de referencia»**. Con el criterio nuevo esa tensión no existe: el boceto no es una autoridad, así que gana la coherencia sin discusión.

Migración `complete_tenant_fk_coherence`:

- **+2 FK nuevas** (`exam_attempts`, `student_progress`) → `institutions(id) ON DELETE CASCADE`, con detección previa de huérfanos.
- **2 FK endurecidas** (`students`, `group_students`) de `NO ACTION` a `CASCADE`.

Resultado: **18 columnas `institution_id`, las 18 con FK**; 17 en `CASCADE` y solo `users` en `SET NULL`, que es deliberado — dar de baja un centro no borra a las personas.

Las dos que pasan a `CASCADE` son hoy **inalcanzables**: no existe `DELETE /institutions`, solo `PATCH /institutions/{id}/toggle`. Se arreglan precisamente por eso: el día que ese endpoint exista, `NO ACTION` en `students` habría hecho fallar el borrado con un 500, que es exactamente el fallo de §5 que costó tanto descubrir. **Cobertura:** 3 tests nuevos en `CascadeIntegrityTest`, incluido el borrado completo de una institución.

### 7.3 Los borrados pasaron a ser realmente destructivos → **abierto, pero en el frontend**

Efecto colateral de arreglar §5: antes fallaban con 500 y eso **protegía por accidente**. Ahora `DELETE /api/subjects/{id}` elimina la materia, sus exámenes, preguntas, intentos y **todos los resultados de los alumnos**, en silencio y sin vuelta atrás.

Es el comportamiento que la documentación ya afirmaba, y es la regla de negocio correcta. Lo que falta no es del modelo de datos:

- Confirmación explícita en el frontend, con el recuento de lo que se va a perder (`GET /api/subjects` ya devuelve `exams_count`).
- Valorar borrado lógico para materias y grupos, en línea con el `left_at` que ya se usa en `group_students`.

Con la §7.2 cerrada, esto se extiende a `DELETE /institutions` si algún día se expone: borrar un centro arrasa ahora con todo su contenido.

---

## 8. Para el capítulo del informe

Material aprovechable directamente:

- §3 y §4 → tabla de entidades y relaciones, con las decisiones de diseño justificadas.
- §2 → apartado de metodología de verificación (comparación automatizada + validación empírica).
- §5 → hallazgo defendible: un análisis que destapó cuatro operaciones rotas en producción que las pruebas existentes no cubrían, con la explicación de por qué se les escapaban.
- §6 → estrategia de migración con salvaguardas y reversibilidad.
- §9.8.1 → decisión de arquitectura sobre dónde se genera el PDF, con las dos alternativas comparadas y el criterio que decidió. Es de las pocas decisiones del proyecto en las que se **implementaron ambas** antes de elegir, así que se defiende con evidencia y no con preferencia.

---

## 9. Qué hay que actualizar en el informe

Referencia: `CTFG-DOC-18_Guia_para_Informe_Final_TFG 2025.docx`. Los números entre corchetes son el índice de párrafo con texto del documento (`word/document.xml`), útil para localizar el pasaje con Buscar.

### 9.1 🔴 Crítico — el informe describe un stack que no es el construido

Estas afirmaciones son **falsas respecto al sistema entregado**. En una defensa son indefendibles: basta abrir el repositorio.

| # | Dónde | Dice el informe | Realidad verificada | Evidencia |
|---|---|---|---|---|
| 1 | **[398]** «Node.js y PostgreSQL (Gestión de Base de Datos)» | *«La conexión y la manipulación de los datos se realizó con node.js, que actúa como un intermediario entre la base de datos y el backend»* | **No existe tal intermediario.** Laravel accede a PostgreSQL directamente por PDO/Eloquent | `composer.json` (`laravel/framework`, driver `pgsql`); `package.json` del backend **sin `dependencies`** |
| 2 | **[210]** Procesos auxiliares y asincronía | *«Para tareas en segundo plano… se ha integrado Node.js»* | Las colas son de Laravel (`QUEUE_CONNECTION=database` + `php artisan queue:work`). Los reportes no son tarea en segundo plano: el CSV se transmite en la misma petición desde `ReportExportService` y los agregados de los gráficos salen de `ReportMetricsService` en un solo SELECT. PhpSpreadsheet está en el proyecto para **leer** los XLSX de la carga masiva, no para generar reportes | `routes/console.php`, `app/Services/Admin/ReportExportService.php`, `app/Services/Admin/ReportMetricsService.php` |
| 3 | **[399-400]** «Next.js (Integración de servicios web)» | *«Next.js se incorporó… renderizado del lado del servidor»* | **No se usa Next.js.** El frontend es **React 19 + Vite 8 + TypeScript 6 + Tailwind 4**, SPA sin SSR | `NeoEduCoreFront/NeoEduCore2/package.json`: `next` **no está presente** |
| 4 | **[684]** Módulo de Seguridad | *«Implementar autenticación con JWT»* | Es **Laravel Sanctum**: tokens opacos en la tabla `personal_access_tokens`, no JWT. Sin ningún paquete JWT instalado | `composer.json` contiene `laravel/sanctum`; no hay `tymon/jwt-auth` ni `firebase/php-jwt` |
| 5 | **[555]** Sprint 3 | *«Generar y devolver token JWT»* | Ídem: `AuthController::login` emite un token Sanctum | `app/Http/Controllers/AuthController.php` |
| 6 | **[689]** Módulo de Infraestructura | *«Desplegar el sistema en Render (producción) y Railway (pruebas)»* | Despliegue real: **DigitalOcean + Coolify**, contenedor Docker con **FrankenPHP + Laravel Octane**; base de datos en **Supabase** (PostgreSQL 17.6, pooler en modo *session*, puerto 5432) | `Dockerfile`, `DEPLOY_COOLIFY.md`, `.env` |

**Nota sobre el nº 6:** el informe sí acierta en *«usar contenedores Docker»* [690]. Lo que hay que corregir es el proveedor.

**Corrección sugerida para el apartado de fundamentación tecnológica:** sustituir los epígrafes «Node.js y PostgreSQL» y «Next.js» por:

- **PostgreSQL (gestión de datos)** — sin intermediario; acceso desde Laravel vía Eloquent ORM y PDO.
- **Laravel Octane + FrankenPHP (servidor de aplicación)** — mantiene la aplicación en memoria entre peticiones, lo que sustenta el requisito de concurrencia.
- **Laravel Sanctum (autenticación)** — tokens de API con expiración configurable y revocación en base de datos.
- **Supabase (PostgreSQL gestionado)** — con la justificación del pooler en modo *session* (ver `ESTADO_Y_PENDIENTES.md`, cambio E9: el pooler en modo *transaction* rompía los prepared statements de PDO).

### 9.2 🟠 El informe no tiene diagrama de modelo de datos

El índice de figuras [56-65] lista diez figuras: mapa conceptual, diagrama de clases, diagrama de proceso, flujo de examen, arquitectura en módulos, flujo de autenticación, arquitectura general, arquitectura del sistema, casos de uso y flujo de chatbot.

✅ **Los diagramas ya están dibujados** — [`DIAGRAMAS.md`](DIAGRAMAS.md), 08/08/2026: modelo entidad-relación, aislamiento multi-tenant, clases, arquitectura, y los flujos de autenticación, examen, tutor IA, casos de uso y ciclo académico. Derivados del código y verificados contra `01_schema.sql`. Queda incorporarlos al informe.

**No había diagrama entidad-relación ni modelo de datos**, pese a que el sistema tiene **15 entidades, 4 tablas pivote y 47 claves foráneas**. Para un TFG de sistema de información es una ausencia llamativa, y además es justo el capítulo que este documento permite redactar con solvencia.

> ⚠️ **Cifras corregidas el 08/08/2026.** Este apartado decía «17 entidades … 42 claves foráneas»: las 42 eran el conteo **previo** a la migración de §6, que añadió 3. El recuento verificado contra `database/sql/01_schema.sql` es **19 tablas de dominio** (15 entidades + 4 pivotes: `group_students`, `exam_targets`, `student_answer_options`, `student_subjects`) más 5 de framework, y **45 FK**. Desglose en `ESTADO_Y_PENDIENTES.md` §3.5.

**Acción:** añadir una figura de modelo relacional a partir de §3 y §4, y un diccionario de datos.

### 9.3 🟠 Entidades del sistema ausentes del informe

Las nueve relaciones de §3.4 corresponden a dos entidades que el informe no menciona:

| Entidad | Tabla | Qué es | Por qué falta |
|---|---|---|---|
| **`AiChatSession`** | `ai_chat_sessions` | Sesión del tutor IA conversacional: historial de mensajes en `jsonb`, vinculada a estudiante, materia y examen | Añadida el 29/04/2026, después del diseño original |
| **`StudentSubject`** | `student_subjects` | Matrícula estudiante↔materia, con `enrolled_at` y unicidad `(student_user_id, subject_id)` | Añadida el 09/05/2026 |

Ambas son funcionalidad central del sistema entregado —el tutor IA es el diferenciador del proyecto— así que su ausencia del modelo documentado es una omisión de peso.

**Acción:** incorporarlas al diagrama de clases [653] y al modelo de datos nuevo (§9.2).

### 9.4 🟡 Decisiones de diseño que conviene justificar, no ocultar

Las dos divergencias deliberadas de §4 deben aparecer como **decisiones argumentadas**, no como desviaciones:

- `exams.created_by_teacher_id` → `SET NULL` (nullable): permite dar de baja a un docente conservando sus exámenes. Con el diseño original, borrar un profesor sería imposible mientras tuviera exámenes creados.
- `exams.subject_id` → `CASCADE`: coherente con la regla de que eliminar una materia elimina su contenido académico.

Redactadas así refuerzan el capítulo en vez de debilitarlo: muestran criterio de diseño y no un descuido.

### 9.5 🟡 Requisitos no funcionales: estado real

El informe fija tres requisitos de rendimiento [695-697]. Estado actual según `ANALISIS_CONCURRENCIA.md`:

Prueba de carga ejecutada el 03/08/2026 (k6 + base local desechable). Detalle en `ANALISIS_CONCURRENCIA.md` §6.

| Requisito del informe | Estado | Evidencia |
|---|---|---|
| «Responder cada petición en ≤ 2 s bajo carga normal (<50 usuarios concurrentes)» | ✅ **Medido** | Ciclo completo de entrega p95 = 1,19 s con 8 workers; lecturas 313-315 ms |
| «Soportar al menos 200 usuarios concurrentes en pruebas de carga» | 🟡 **Parcialmente validado** | Medido que el throughput **escala ×6,6 de 1 a 8 workers** y que bajo sobrecarga **degrada encolando, con cero errores 5xx**. Falta repetirlo sobre el despliegue real con Octane |
| «Generar un reporte de 1000 estudiantes en menos de 5 s» | ✅ **Medido y mejorado** | Con 1.000 estudiantes y 20.000 respuestas: **3.790 ms → 1.068 ms** tras corregir un N+1 en la exportación CSV |

**Acción:** el informe puede ahora **respaldar los tres requisitos con datos**, que es mucho más sólido que afirmarlos sin medir. Para el segundo conviene precisar la redacción: está validado el comportamiento de escalado y la ausencia de errores bajo saturación, no la cifra exacta de 200 sobre la infraestructura final.

### 9.6 🟡 Roles: el informe menciona tres, el sistema tiene cuatro

[664] dice *«Asignar roles con diferentes permisos (admin, docente, estudiante)»*. El enum `UserType` tiene **cuatro**: `admin`, `teacher`, `student` y **`parent`**.

`parent` está reservado para un futuro portal de acudientes y **hoy no tiene superficie de API** (decisión documentada el 26/06/2026). Conviene mencionarlo así explícitamente, como previsión de diseño, en vez de dejarlo sin documentar.

### 9.7 ✅ Lo que el informe sí describe correctamente

Para no tocar lo que está bien:

- **Laravel como backend principal** [207, 393-394] — correcto.
- **React como frontend** [395-396] — correcto (React 19).
- **PostgreSQL como motor de base de datos** [212-213] — correcto.
- **OpenAI para el tutor virtual** [401-402] — correcto (`gpt-4o-mini`).
- **TypeScript y Tailwind CSS** [205] — correcto.
- **Bcrypt para contraseñas** [685] — correcto (`Hash::make`, `BCRYPT_ROUNDS`).
- **Contenedores Docker** [690] — correcto.

### 9.8 🟢 Resuelto — reportes en PDF y CSV con gráficos

[215] afirma: *«La plataforma genera informes descargables en **PDF** y CSV»*, y [734-735] pide *«Exportar reportes en formatos PDF y CSV»* y *«Mostrar gráficos interactivos (barras, líneas, pastel)»*.

**Estado anterior (03/08/2026):** solo existía `/reports/exams/{exam}/results.csv`. No había PDF, no había exportación individual y no había ninguna serie agregada para graficar.

**Decisión de arquitectura (05/08/2026): el reparto es backend↔frontend.** El backend expone datos agregados; **el PDF con gráficos lo compone el frontend**. El detalle de la decisión, con las dos alternativas y el porqué, va en §9.8.1 — es material directamente aprovechable para el capítulo de arquitectura del informe.

Lo que se añadió en el backend:

| Endpoint | Qué entrega |
|---|---|
| `GET /reports/exams/{exam}/summary` | Totales del grupo (media, mediana, mejor/peor, aprobados, tasa), `score_distribution` (**barras**) y `performance_levels` (**pastel**) |
| `GET /reports/students/{id}/summary?points=` | Totales del estudiante, `score_trend` (**líneas**, del intento más antiguo al más reciente) y `subject_mastery` (**barras**) |
| `GET /reports/students/{id}/history.csv` | Exportación individual, que faltaba: antes solo se podía exportar el reporte grupal |

Dos detalles que conviene llevar al informe porque son decisiones, no accidentes:

- **`passing_percentage`** (nota mínima de aprobación, 65 por defecto — el mínimo de promoción del MEP en I y II ciclo) pasó a vivir en `institutions.settings`, editable con `PUT /api/system/config`. Antes el sistema no tenía ningún concepto de «aprobado»: sin él no se puede hablar de tasa de aprobación ni de niveles de desempeño. Los cortes de los cuatro niveles cuelgan de ese valor con `max()` para que no se crucen si un centro fija una nota mínima alta.
- **Los cuatro niveles de desempeño son categorías ordenadas**, no identidades. El frontend debe pintarlos con una rampa de un solo tono (claro→oscuro), no con colores categóricos sueltos, y nunca con el par verde/rojo: es justo el que se confunde bajo daltonismo deuteranope.

**Rendimiento.** Los agregados se resuelven en **un solo SELECT** con `COUNT(*) FILTER`, no recorriendo intentos en PHP. `QueryBudgetTest::test_exam_summary_cost_is_flat_per_attempt_count` falla si alguien lo reescribe en PHP, que es lo que rompería el requisito de los 1.000 estudiantes en menos de 5 s (§9.5).

**Lo que queda pendiente y no es del backend:** que el frontend efectivamente genere el PDF. Hasta que eso exista, la afirmación [215] sigue siendo parcialmente falsa a nivel de sistema — descargable en CSV sí, en PDF todavía no.

Lo demás de ese párrafo sí es correcto: las métricas se presentan por estudiante, grupo e indicador (`/analytics/institution`, `/analytics/subjects`, `/analytics/students/{id}`).

#### 9.8.2 Estrategias del tutor — requisito [740]

*«Permitir descargar estrategias del tutor en PDF»* [740] quedó resuelto del lado del backend el 05/08/2026, con el mismo reparto de §9.8.1: el endpoint entrega los datos agrupados y el frontend compone el documento.

| Endpoint | Quién | Alcance |
|---|---|---|
| `GET /reports/students/me/strategies` | Estudiante | Sus propias recomendaciones |
| `GET /reports/students/{id}/strategies` | Docente | **Solo** recomendaciones nacidas de exámenes que él creó |
| ídem | Admin | Toda su institución |

**La decisión de fondo fue de audiencia, y el informe se contradice a sí mismo en este punto.** [175] dice que el tutor conversa *«directamente al estudiantado»* y que *«el personal docente no verá mensajes individuales: recibirá solo métricas agregadas»*; pero [738] pide *«recomendaciones pedagógicas personalizadas por estudiante»* y [741] *«registrar interacciones del tutor para seguimiento pedagógico»*, que sin acceso al contenido no sirven de nada.

La lectura adoptada —y que conviene dejar explícita en el informe, porque resuelve la contradicción en vez de esconderla— es que **la frontera no es alumno/docente, sino conversación/recomendación**:

| Artefacto | Tabla | Estudiante | Docente |
|---|---|---|---|
| Conversación con el tutor | `ai_chat_sessions` | Sí | **Nunca**, en ningún reporte |
| Recomendaciones estructuradas | `ai_recommendations` | Sí | Sí, acotado a sus exámenes |
| Métricas de uso agregadas | — | — | Sí (`/reports/ai/tutor-usage`) |
| Ranking nominal de uso | — | — | **No** — solo admin |

> ⚠️ **Corregido el 08/08/2026.** La última fila no existía, y era una omisión con consecuencia: `/reports/ai/tutor-usage` devolvía al docente un `top_students_by_usage` con el **nombre** de cada alumno y cuántos mensajes había escrito al tutor. La tabla lo presentaba como «métricas agregadas», pero un ranking nominal de menores por su uso del tutor no es agregado ni anónimo, que es lo que [175] promete en las dos palabras. Queda solo para admin. Ver `ESTADO_Y_PENDIENTES.md` H5.

Así [175] se cumple al pie de la letra —el docente no ve un solo mensaje del chat— y [738]/[741] también, porque el seguimiento pedagógico se apoya en las recomendaciones, que es el artefacto que el propio informe describe como *pedagógico*. `TutorStrategiesTest::test_chat_history_never_appears_in_the_strategies_report` fija esa frontera como prueba automatizada.

**Hallazgo de seguridad corregido en el camino.** `AiRecommendationController::show()` ya acotaba al docente a sus propios exámenes, pero **`index()` no**: un docente podía listar el `recommendation_text` de cualquier alumno de la institución, incluidos exámenes ajenos. Las dos vías decían cosas distintas sobre los mismos datos —textos generados por IA sobre el desempeño de menores— y se alineó `index()` con la regla restrictiva. **Ninguna prueba existente lo detectó**, lo que refuerza el punto de la revisión de seguridad por rol que sigue pendiente.

**Pendiente y no es del backend:** que el frontend genere el PDF, igual que en §9.8.

#### 9.8.1 Por qué el PDF no se genera en el backend

**Conviene dejar claro de entrada que no es una limitación técnica.** La alternativa de servidor se implementó y **funcionaba**: `barryvdh/laravel-dompdf` componiendo el documento desde plantillas Blade, con los gráficos dibujados en PHP con GD e incrustados como PNG en base64 (`data:` URI, de modo que dompdf no necesitaba `isRemoteEnabled` y no quedaba expuesto a SSRF desde la plantilla). La fuente con cobertura de acentos —DejaVuSans— venía dentro del propio dompdf, y la extensión GD ya estaba en el `Dockerfile`. Se generaron los dos reportes completos, con sus indicadores, sus tres gráficos y sus tablas. Se descartó **después** de verlo funcionar, y se revirtió por completo (el prototipo nunca llegó a commitearse).

Es, por tanto, una decisión de diseño argumentada, no un requisito que no se pudo cumplir. Comparación de las dos opciones:

| Criterio | PDF en el backend (dompdf) | PDF en el frontend (elegida) |
|---|---|---|
| **Coste por reporte** | Segundos de CPU y memoria **en los workers de Octane que atienden la API** | Lo paga la máquina del usuario; el servidor solo sirve JSON |
| **Efecto en la concurrencia** | Cada PDF en curso es un worker que no responde peticiones — impacto directo sobre el techo del modelo de `ANALISIS_CONCURRENCIA.md` | Ninguno |
| **Definición del gráfico** | **Duplicada**: una vez en GD para el PDF y otra en React para la pantalla | **Única**, en React |
| **Riesgo de divergencia** | Alto: al cambiar un rango de notas o un color hay que tocar ambos motores, o el PDF y la pantalla acaban diciendo cosas distintas | Nulo por construcción: el PDF sale de lo que el usuario ya está viendo |
| **Capa de presentación en la API** | Sí — maquetación, tipografía y paleta dentro del backend | No |
| **Fidelidad del documento** | Idéntico para todos, independiente del navegador | Depende del motor del cliente |
| **Generación desatendida** (envío programado por correo, lotes) | Posible | **No posible**: exige un navegador abierto |

**Criterio que decidió: agilidad y capacidad del servidor.** Componer el documento en la máquina del docente libera al servidor de una tarea cara y elástica, y el número de PDF generados a la vez deja de competir con la capacidad de atender exámenes —que es el pico real del sistema, porque todos los alumnos entregan a la vez—. Se acepta a cambio la dependencia del navegador del cliente, un costo menor aquí: el documento se genera bajo demanda y a la vista del usuario, así que un fallo de composición es visible y reintentable en el acto, no silencioso.

**Hay una tensión aparente con el informe que conviene abordar de frente**, porque en una defensa se puede señalar. [137] y [154] prometen *«una interfaz que no exige grandes recursos tecnológicos»* y operar *«en centros con recursos limitados»*; mover trabajo al cliente parece ir en contra. No lo es, por dos motivos concretos:

1. **La carga en el cliente está acotada.** El documento tiene cuatro gráficos y una tabla de detalle con tope de filas; el resto de la información son agregados ya calculados. Es trabajo de fracciones de segundo en cualquier navegador de la última década, no comparable con lo que cuesta en el servidor multiplicado por usuarios concurrentes.
2. **En conectividad limitada el reparto elegido es el que gana**, y esto sí es medible. Los PDF del prototipo de servidor pesaban **~910 KB cada uno**, casi todo PNG de los gráficos; la respuesta JSON equivalente son unos pocos KB. Con la conexión pobre que el propio informe da por supuesta [NFR de rendimiento], descargar 910 KB por cada reporte es peor experiencia que recibir los datos y dibujar en local. El requisito de recursos limitados apunta, en realidad, en la misma dirección que la decisión.

A esto se suma que NeoEduCore está planteado como **API pura**: el frontend es una SPA en React y el backend no sirve HTML en ninguna ruta. Meter plantillas de documento rompía esa separación por una sola funcionalidad.

**Las dos opciones no son excluyentes, y conviene decirlo así en el informe.** El reparto habitual es que el frontend genere el PDF que el usuario pide en pantalla, y que el servidor lo genere solo para lo desatendido. Si en algún momento aparece el requisito de **enviar los reportes por correo o generarlos en lote**, la generación en servidor deja de ser opcional: ahí no hay navegador. Es la vía de crecimiento natural, y lo construido no la cierra —los mismos endpoints `summary` alimentarían un generador de servidor sin cambios.


### 9.8.3 🔴 Hallazgos de seguridad encontrados al implementar los reportes

Cuatro hallazgos, todos **verificados ejecutando peticiones reales**, no leyendo código. Los cuatro estaban corregidos al cierre del 05/08/2026. Lo relevante para el informe no es la lista sino el patrón, y que **ninguna de las 257 pruebas existentes detectaba ninguno**.

| # | Dónde | Qué pasaba | Severidad |
|---|---|---|---|
| 1 | `GET /exams`, `GET /exams/{id}`, `GET /exams/{id}/questions` | Un estudiante listaba el catálogo completo de su institución —**incluidos borradores**— y, con los ids que ese mismo listado le daba, leía **los enunciados de cualquier examen antes de presentarlo** | **Alta** |
| 2 | ídem | La relación `teacher` se serializaba entera: el **correo del docente** llegaba al alumnado | Media |
| 3 | ídem | `max_attempts`, `randomize_questions`, `allow_review_after_submission` y `show_results_immediately` visibles para el estudiante | Baja |
| 4 | `GET /ai-recommendations` | `index()` no aplicaba la restricción de examen propio que sí aplicaba `show()`: un docente leía el `recommendation_text` de cualquier alumno de la institución | Media |

**Sobre el nº 1.** Las respuestas correctas **nunca** se filtraron: `is_correct` y `correct_answer_text` van ocultos en los modelos y `RevelaRespuestas` solo los expone a admin y docente —el arreglo de G12 aguantó—. Lo que se filtraba era el **enunciado**, que en un sistema de exámenes diagnósticos invalida igual la medición: basta con leer las preguntas por anticipado o compartirlas. Lo llamativo es que **la regla correcta ya estaba escrita** en `StudentController::availableExams()` (activo + dentro de la ventana + asignado a sus grupos); simplemente no se aplicaba en `/exams`.

**El patrón, que es lo defendible en el informe.** Los cuatro salen del mismo sitio: el grupo de rutas de *lectura compartida* aplica un único middleware de rol (`role:admin,teacher,student`) y deja el estrechamiento posterior **a criterio de cada controlador**. Algunos lo hacían (`QuestionController` con `RevelaRespuestas`, `ExamAttemptController::show` con el guard de `allow_review_after_submission`) y otros no lo hacían en absoluto. No era un descuido puntual: no existía ningún mecanismo que lo obligara, y por eso el mismo error reaparece en controladores distintos.

**Corrección aplicada.** Se centralizó la definición de «examen visible» en `Exam::scopeVisibleTo()` —una sola fuente, reutilizada por `/exams`, `/exams/{id}`, `/exams/{id}/questions` y `availableExams()`, que antes la duplicaba— y el recorte de campos en el trait `AcotaExamenAlEstudiante`, hermano de `RevelaRespuestas`. Se devuelve **404 y no 403** en los exámenes no visibles: un 403 confirmaría al alumno que la prueba existe.

**Cobertura añadida:** `ExamVisibilityTest` (8 casos). Se comprobó que **fallan sin el arreglo** neutralizando el scope, para que sean pruebas de regresión de verdad y no aserciones que pasan por casualidad.

**Lo que esto dice del resto del sistema.** Es el segundo hallazgo de la misma familia tras G12, y otra vez apareció de casualidad —implementando reportes, no auditando—. Refuerza que la revisión de seguridad por rol pendiente debe hacerse de forma sistemática y endpoint por endpoint, preguntando **qué campos ve cada rol**, no solo si el rol tiene acceso a la ruta.

### 9.8.4 🟢 Documentación de la API — RNF de mantenibilidad

El informe exige *«La API debe documentarse con OpenAPI»* (RNF de mantenibilidad, [~700]). Dice además **«OpenAPI 5.0»**, versión que **no existe**: la especificación va por 3.x. Conviene corregirlo en el informe.

**Estado anterior:** `storage/api-docs/api-docs.json` documentaba **6 de 103 endpoints** (5,8 %) y llevaba sin regenerarse desde el 18/04/2026. No era un fichero incompleto: las anotaciones `#[OA\...]` solo existían en 2 controladores.

**Resuelto el 05/08/2026 invirtiendo el flujo.** En vez de anotar 97 endpoints a mano —más de 2.000 líneas de atributos dentro de los controladores, que además se desincronizan en cuanto alguien toca una ruta—, el documento se **deriva de las rutas reales** con `php artisan openapi:generate`, el mismo patrón que ya usaba la colección de Postman. De cada ruta se deducen método, path, parámetros, si exige token, **qué roles la pueden usar** (leyendo el middleware) y los códigos de respuesta que se siguen de eso.

Cobertura resultante: **103/103 endpoints, 74 paths, 16 módulos.** Los metadatos que no se pueden deducir de una ruta —nombre del módulo, cuerpo de ejemplo, si es pública— viven en `App\Support\ApiSpec`, **compartidos con el generador de Postman** para que los dos no se contradigan.

Limitación honesta, que conviene declarar en el informe en vez de ocultar: **no hay esquema detallado de la respuesta 200**. Se documenta la superficie de la API (qué existe, quién puede llamarlo, qué devuelve como código), no la forma exacta de cada payload. Para eso está la colección de Postman, con cuerpos de ejemplo reales.

**Corregido de paso:** el `securityScheme` declaraba `bearerFormat: 'JWT'`. Sanctum emite tokens **opacos** contra `personal_access_tokens`. La documentación generada estaba respaldando el mismo error que §9.1 nº 4 señala en el informe.

⚠️ **No ejecutar `php artisan l5-swagger:generate`**: sobrescribiría el fichero con los 6 endpoints anotados. L5-Swagger se conserva solo para servir la interfaz en `/api/documentation`. Avisado en `config/l5-swagger.php` y en el `README.md`.

### 9.9 Resumen accionable

| Prioridad | Acción | Tipo | Apartados |
|---|---|---|---|
| 🔴 1 | Reescribir la fundamentación tecnológica: fuera Node.js como capa de datos, fuera Next.js | Redacción | [210], [397-400] |
| 🔴 2 | Corregir JWT → Laravel Sanctum | Redacción | [555], [684] |
| 🔴 3 | Corregir Render/Railway → DigitalOcean + Coolify + Supabase | Redacción | [689] |
| 🟢 4 | ~~Exportación a PDF~~ — **backend resuelto** el 05/08/2026: `summary` con las series de barras/líneas/pastel + CSV individual. Falta que el frontend arme el PDF | **Alcance** | [215], [734-735] |
| 🟢 10 | ~~Descargar las estrategias del tutor en PDF~~ — **backend resuelto** el 05/08/2026: endpoints de estrategias con el chat excluido por diseño (§9.8.2). Falta que el frontend arme el PDF | **Alcance** | [740] |
| 🟠 5 | Añadir figura de modelo de datos y diccionario de datos | Contenido nuevo | Índice de figuras + cap. IV |
| 🟠 6 | Incorporar `AiChatSession` y `StudentSubject` al diagrama de clases | Contenido nuevo | [653] |
| 🟡 7 | Justificar las 2 decisiones de diseño de §4 | Redacción | Capítulo IV |
| 🟡 8 | Medir o matizar los 3 requisitos de rendimiento | **Medición** | [695-697] |
| 🟡 9 | Documentar el rol `parent` como previsión de diseño | Redacción | [664] |

**Siete de diez se resuelven reescribiendo texto.** Las tres que no:

- **Exportación a PDF (nº 4)** — el backend ya entrega las series de los tres gráficos y el CSV individual (§9.8). Queda que el frontend componga el documento.
- **Estrategias del tutor en PDF (nº 10)** — backend resuelto (§9.8.2); queda el documento en el frontend.
- **Prueba de carga (nº 8)** — medición pendiente sobre el despliegue real con Octane.

⚠️ **Los números de párrafo de esta tabla y de toda la §9 están desfasados.** El `.docx` se editó después de escribirla. Equivalencias verificadas el 08/08/2026 en **§10.3**.

---

## 10. Contradicciones del informe

> **Para qué es esta sección.** Dejar por escrito, con la cita y el número de párrafo, cada punto en el que el informe **se contradice a sí mismo** o **contradice al sistema entregado**, para que el equipo entre después a modificar el documento sin tener que volver a encontrarlas.
>
> No hay ninguna acción de código aquí: por el criterio de la cabecera, todas se resuelven escribiendo. Se separan las internas (§10.1) de las que enfrentan informe y sistema (§10.2) porque **las internas son las peligrosas en una defensa**: no hace falta abrir el repositorio para verlas, basta leer el propio informe.

### 10.1 El informe contra sí mismo

| # | Dónde | Una parte dice | La otra dice | Por qué choca |
|---|---|---|---|---|
| **C1** | [165] y [505] vs [173] | Objetivo específico: integrar el tutor «para generar sugerencias en tiempo real, dirigidas tanto a estudiantes **como a docentes**» | «recomendaciones … directamente al estudiantado. **El personal docente no verá mensajes individuales**: recibirá solo métricas agregadas» | Es una contradicción de **audiencia**, y pesa el doble porque una de las dos partes es un **objetivo específico del TFG**, que es lo que se evalúa como cumplido o no |
| **C2** | [396] vs [173] | «El contenido producido por el tutor **será revisado periódicamente por el equipo docente** participante, para validar su pertinencia pedagógica» | «El personal docente **no verá mensajes individuales**» | No se puede revisar periódicamente el contenido que el propio informe prohíbe ver. Aparece en el capítulo de consideraciones éticas, que es donde más se nota |
| **C3** | [737] y [739] vs [173] | «Generar **recomendaciones pedagógicas personalizadas por estudiante**» · «**Registrar interacciones del tutor** para seguimiento pedagógico» | ídem | Un seguimiento pedagógico sin acceso a nada individual no es accionable. **Esta es la única que el sistema ya resuelve**: ver §9.8.2 — la frontera real es *conversación vs. recomendación*, no *alumno vs. docente* |
| **C4** | [225] y [804] vs [296-297] y [417-418] | «El entorno de usuario fue construido con React … **y Next.js**» · «arquitectura general del sistema **en Next.js**. Los Server y Client Components…» | Los apartados de comparativa y de stack presentan React + TypeScript sin SSR | El informe describe **dos frontends distintos**. Además ninguno de los dos existe tal cual: es React 19 + Vite, sin Next.js (§9.1 nº 3) |
| **C5** | [419-420] vs [228-229] y [415-416] | «La conexión y la manipulación de los datos se realizó con **node.js, que actúa como un intermediario** entre la base de datos y el backend» | Laravel como backend principal, con PostgreSQL | Dos rutas de datos incompatibles en el mismo documento. La real es Laravel→PDO→PostgreSQL, sin intermediario (§9.1 nº 1) |
| **C6** | [236] vs [732] | «La plataforma **genera** informes descargables en PDF y CSV» (presente, como hecho consumado) | «**Exportar** reportes en formatos PDF y CSV» (listado como requisito por implementar) | El mismo entregable aparece como terminado en un capítulo y como pendiente en otro. Hoy la afirmación en presente es **parcialmente falsa**: CSV sí, PDF lo compone el frontend y todavía no existe |
| **C7** | [390] vs [732-733] | «Documentación y reportes: **Se generó** reportes automáticos en PDF y CSV con gráficos explicativos» | ídem | Igual que C6, en el capítulo de metodología y en pasado. Es el tiempo verbal el que afirma de más |
| **C8** | [173] vs [396] | Criterios de éxito medibles: «cero incidentes de PII», «**más del 75 % de mensajes que superen validación**» | La validación pedagógica se describe como revisión humana periódica | Los dos mecanismos de control se solapan sin decir cuál manda. Y el criterio del 75 % **hoy no es calculable**: ver §10.2 nº 6 |

### 10.2 El informe contra el sistema entregado

Se listan solo las que **no** están ya recogidas en §9.1 (stack tecnológico) para no duplicar.

| # | Párrafo | El informe dice | El sistema hace | Corrección sugerida |
|---|---|---|---|---|
| 1 | [122], [222], [255], [737] | El tutor analiza los resultados del diagnóstico y **genera recomendaciones personalizadas** | En el submit las genera un `if/elseif/else` sobre el porcentaje, **sin IA**. OpenAI solo interviene si el alumno pulsa regenerar | **Es la contradicción más expuesta del TFG**, porque el tutor es el diferenciador del proyecto. Redactar el diseño real: heurística inmediata + refinamiento IA bajo demanda, justificado por concurrencia y coste. Alternativa: diferir la generación IA a la cola (código). Comparadas en `ESTADO_Y_PENDIENTES.md` §3.6 nº 1 |
| 2 | [171] y [222] | Banco de ítems «con metadatos de **tema, indicador y dificultad**» · sugerencias «alineadas con los **indicadores curriculares**» | `questions` tiene `question_text`, `question_type`, `points`, `correct_answer_text`, `order_index`. **Ni tema, ni indicador, ni dificultad.** La única señal es `mastery_percentage` **por materia** | Recortar la granularidad prometida en [171], [222], [263] y [276], o añadir las columnas. Bloquea además el entregable del banco de 60 ítems |
| 3 | [263] y [276] | Personalización por área concreta: «si un estudiante tiene dificultad en **comprensión de lectura**…» | La personalización real es «Español 45 %, Ciencias 78 %» | Consecuencia directa del nº 2. Reformular con el nivel de detalle que el sistema sí da: por materia |
| 4 | Figura 10 [816] | El tutor entrega «**recursos personalizados**» | Corregido el 08/08/2026: filtra por rango de grado y prefiere dificultad básica. **Sigue sin poder acotar por materia**: `study_resources` no tiene `subject_id` | Describir la personalización por grado y dificultad, sin prometer por materia |
| 5 | [397] | «**Cada vez que el tutor virtual intervenga**, el sistema informa claramente que las sugerencias provienen de un modelo de lenguaje automatizado» | La respuesta de `/ai/tutor/chat` es `{session_id, reply, message_count}`. No hay campo de aviso | O el aviso viaja en la respuesta (código), o el informe lo atribuye explícitamente a la interfaz. Hoy no lo dice ninguno de los dos |
| 6 | [173] | «registrará incidencias» · criterio de éxito «más del 75 % de mensajes que superen validación» | Los bloqueos por PII o enlace no permitido solo dejan `Log::warning`. **No se persiste ni el total validado ni el bloqueado** | El criterio **no es demostrable** tal como está escrito. O se añade el contador, o se sustituye por uno que sí se pueda medir |
| 7 | [173] | Recomendaciones «breves (**2–4 oraciones**)» | System prompt: «máximo 4 párrafos», 600 tokens (800 en modo práctica) | Ajustar la cifra del informe, o el prompt. La cifra del informe es la que se lee en la defensa |
| 8 | [222] | «modelos **GPT-4** (variante ligera y de menor coste del sistema GPT-4 de OpenAI)» | `gpt-4o-mini`, que es variante de **GPT-4o**, no de GPT-4 | Nombrar el modelo exacto |
| 9 | [758] | «Los tokens de sesión deben expirar tras **60 minutos** de inactividad» | `SANCTUM_TOKEN_EXPIRATION_MINUTES` = **12 h** | Aquí conviene **no** aplicar el criterio general: son cuentas de menores, posiblemente en equipos compartidos del centro. Es más defendible bajar la variable que relajar el requisito |
| 10 | [771] | «La API debe documentarse con **OpenAPI 5.0**» | Esa versión **no existe**: la especificación va por 3.x, y lo generado es 3.0 | Corregir la versión |
| 11 | [720] | «Asignar roles con diferentes permisos (**admin, docente, estudiante**)» | El enum `UserType` tiene **cuatro**: se suma `parent`, reservado y sin superficie de API | Documentar `parent` como previsión de diseño (§9.6) |

### 10.3 ⚠️ Los números de párrafo de la §9 están desfasados

El `.docx` se editó después de escribirse la §9, y todas sus referencias se corrieron. Equivalencias verificadas el 08/08/2026 con el fichero actual (`word/document.xml`, contando párrafos con texto):

| §9 dice | Ahora es | Contenido |
|---|---|---|
| [137] / [154] | **[152] / [247]** | «recursos limitados», «interfaz que no exige grandes recursos» |
| [175] | **[173]** | Tutor mínimo viable y frontera de privacidad del docente |
| [205] | **[226]** | TypeScript y Tailwind |
| [207] | **[228-229]** | Laravel como backend |
| [210] | **[231]** | Node.js para tareas en segundo plano |
| [212-213] | **[420]** | PostgreSQL como motor |
| [215] | **[236]** | «informes descargables en PDF y CSV» |
| [393-394] | **[415-416]** | Laravel |
| [395-396] | **[417-418]** | React |
| [398] | **[419-420]** | «Node.js y PostgreSQL (Gestión de Base de Datos)» |
| [399-400] | **[421-422]** | «Next.js (Integración de servicios web)» |
| [401-402] | **[426]** | OpenAI para el tutor |
| [555] | **[589]** | Sprint 3, «Generar y devolver token JWT» |
| [653] | **[695]** | Figura 2, Diagrama de clases |
| [664] | **[720]** | Roles (admin, docente, estudiante) |
| [734-735] | **[732-733]** | Exportar PDF/CSV · gráficos interactivos |
| [738] | **[737]** | Recomendaciones pedagógicas personalizadas |
| [740] | **[738]** | Descargar estrategias del tutor en PDF |
| [741] | **[739]** | Registrar interacciones del tutor |
| [684] | **[741]** | Módulo de Seguridad, «autenticación con JWT» |
| [685] | **[742]** | Bcrypt |
| [689] | **[746]** | Render / Railway |
| [690] | **[747]** | Contenedores Docker |
| [695-697] | **[753-755]** | Los tres requisitos de rendimiento |
| [~700] | **[771]** | «OpenAPI 5.0» |

Reproducible sin abrir Word:

```python
import zipfile, re
x = zipfile.ZipFile('CTFG-DOC-18_Guia_para_Informe_Final_TFG 2025.docx').read('word/document.xml').decode('utf8')
for i, p in enumerate(re.findall(r'<w:p[ >].*?</w:p>', x, re.S)):
    t = ''.join(re.findall(r'<w:t(?:\s[^>]*)?>(.*?)</w:t>', p, re.S))
    if t.strip():
        print(f'[{i}] {t}')
```

> El `<w:t` del patrón lleva `(?:\s[^>]*)?` a propósito: con `[^>]*` a secas también casa `<w:tab .../>` y la extracción sale con XML crudo mezclado en el texto.

**Recomendación para el equipo:** al corregir el documento, localizar cada pasaje **por su texto** —con Buscar— y no por el número de párrafo. Los índices se vuelven a desplazar en cuanto alguien añada un párrafo.
