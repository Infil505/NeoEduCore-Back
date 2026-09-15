# NeoEduCore — Checklist de pendientes

**Creada:** 10 de septiembre de 2026 · **última actualización:** 13 de septiembre de 2026
**Base:** estado registrado al 08/08/2026 (375 tests, 117 endpoints)
**Ahora:** 449 tests, 122 endpoints, 4 migraciones nuevas aplicadas

## Avance al 13/09/2026

| Cerrado | Qué se hizo |
|---|---|
| **B1** | Reportes en XLSX, dataset compartido con el CSV |
| **S4** | Inyección de fórmulas en los exportados: confirmada y corregida |
| **D1** | IA de recomendaciones a la cola, disparada al consultar resultados |
| **D2** | Tema, indicador y dificultad en los ítems; materia en los recursos |
| **D4** | Aviso de IA (`ai_notice`) en la respuesta del tutor |
| **D5** | Tabla de incidencias + métrica de plataforma para el superadmin |
| **D3** | Caducidad por **inactividad** (60 min), no por expiración absoluta |
| **D6** | El reset del admin sigue sin activar, pero la respuesta lo advierte |
| **D7** | `max_attempts` se queda visible al alumno (sin cambios de código) |
| **D8** | RLS formalizado en migración: 26 tablas |
| **S1** | Revisión de alcance por rol: 1 hallazgo corregido (`/users`) |
| **S5** | Analíticas por materia: el docente ve lo suyo, el admin todo |
| **S6** | Calendario y recursos: son de quien los crea |
| **B2** | Primaria 1.º–6.º (6–12 años): un solo rango de grados, y el tutor adapta el registro |

**Ya no queda ninguna decisión bloqueante: D1–D8 están cerradas.** Arrastran cerradas
**K1–K6** y **K9**, e **I-11**; queda desbloqueado **E2** (banco de ítems).

**Lo siguiente, por orden:** **P1–P2** (despliegue), que dejaron de ser solo eso:

- **sin worker de cola, D1 no funciona** (los intentos se quedan en `preparing`);
- hay que fijar en Coolify `QUEUE_CONNECTION=database` y **las dos** variables de sesión
  de D3.

> **Cómo usarla.** Marcar `[x]` al terminar y añadir la fecha al final de la línea
> (`— ✅ 12/09/2026`). Si una tarea se descarta, marcarla igual y escribir por qué
> (`— ❌ descartada: se corrigió el informe`), para que no vuelva a aparecer como hueco.
>
> Cada tarea lleva un **código** (`D1`, `S2`…) para referirse a ella en commits y notas,
> y la **fuente** donde está el detalle. Esta lista es el índice de control; el análisis
> y la evidencia siguen viviendo en [`ESTADO_Y_PENDIENTES.md`](ESTADO_Y_PENDIENTES.md) y
> [`ANALISIS_MODELO_DATOS_TFG.md`](ANALISIS_MODELO_DATOS_TFG.md). Al cerrar algo que
> también figure allí, actualizar los dos sitios.
>
> 📊 Hay una copia en Excel, [`CHECKLIST_PENDIENTES.xlsx`](CHECKLIST_PENDIENTES.xlsx), con el
> estado en un desplegable y una hoja de resumen que calcula el avance. **Las dos copias se
> mantienen a mano, no se sincronizan solas** (decisión del 13/09/2026: se marcan las dos).
> El Excel se edita **en sitio**, nunca regenerándolo desde este fichero, o se pierden la
> hoja de resumen y el desplegable. Las filas nuevas van dentro del rango `2:327`, que es
> el que abarcan las fórmulas y la validación.
>
> ⚠️ Lo que depende de producción (despliegue, contraseña del superadmin, asignaciones)
> figura como pendiente según el último registro. **Verificarlo antes de empezar**: pudo
> cambiar sin quedar anotado.
>
> 🗄️ **Las migraciones sí están aplicadas.** Las cuatro del 13/09 se aplicaron en la base
> remota de Supabase, que hace de ambiente de pruebas. Lo que falta desplegar
> es el **código** (P1): las columnas existen, pero nada las usa hasta que el servidor
> corra la versión nueva.

**Orden sugerido:** ~~Decisiones D1–D3~~ → ~~Seguridad S1~~ → **Producción P1–P4** →
Mediciones → Informe. Frontend y entregables pueden avanzar en paralelo; E2 ya no espera
a nada.

---

## Índice

1. [Decisiones que bloquean otras tareas](#1-decisiones-que-bloquean-otras-tareas)
2. [Seguridad](#2-seguridad)
3. [Producción y despliegue](#3-producción-y-despliegue)
4. [Mediciones](#4-mediciones)
5. [Frontend](#5-frontend)
6. [Informe del TFG](#6-informe-del-tfg)
7. [Entregables no-código](#7-entregables-no-código)
8. [Mejoras opcionales](#8-mejoras-opcionales)
9. [Funcionalidad nueva del backend](#9-funcionalidad-nueva-del-backend)

---

## 1. Decisiones que bloquean otras tareas

Primero decidir, después ejecutar. Al tomar la decisión, anotar **qué se eligió** en la línea.

- [x] 🔴 **D1 · Recomendaciones post-examen** — ✅ 13/09/2026 · **elegida (a): diferir la generación IA a la cola**
  - **Con un matiz que cambió el diseño: el disparador no es la entrega, sino la consulta de resultados.** La entrega sigue guardando plantillas al instante; el análisis se encola cuando el alumno abre `GET /exam-attempts/{attempt}/recommendations`. Así el pico de entregas simultáneas no toca OpenAI y solo se paga API por quien va a leerlo.
  - El job `GenerateAiRecommendations` **sustituye** las plantillas de ese intento por las cuatro secciones del modelo. Si OpenAI falla, lanza en vez de volver a escribir plantillas (duplicaría el lote) y el intento queda en `failed`, desde donde el alumno puede regenerar a mano.
  - Esquema nuevo (migración `2026_09_13_000001`, **ya aplicada** en Supabase): `ai_recommendations.attempt_id`, `ai_recommendations.generated_by` (`heuristic`|`ai`) y `exam_attempts.ai_recommendations_status` (`preparing`|`ready`|`failed`).
  - De paso, el cupo de regeneraciones se cuenta por `attempt_id` en vez de por una ventana de `generated_at`.
  - **El informe ya no hay que reescribirlo aquí**: describe lo que el sistema hace. Desbloquea I-3 y K1.
  - ⚠️ **Depende de P1 y P2**: sin worker de cola los intentos se quedan en `preparing`, y `QUEUE_CONNECTION` no puede ser `sync`.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §2 Recomendaciones IA · §3.6 nº 1
- [x] 🔴 **D2 · Metadatos de `questions` y `subject_id` en `study_resources`** — ✅ 13/09/2026 · **se añadieron las columnas, alcance completo**
  - Migración `2026_09_13_000002` (**ya aplicada** en Supabase): `questions.topic`, `questions.indicator`, `questions.difficulty` y `study_resources.subject_id`.
  - **`topic_normalized` es una columna generada por PostgreSQL** (minúsculas, sin espacios sobrantes): la normalización no puede depender de por dónde se escriba. El tema se modela como **texto libre normalizado**, no como catálogo — se valoró y se descartó por coste (tabla, CRUD, pantalla y otro diagrama).
  - Consumidores ya conectados: diagnóstico del tutor por tema, `GET /reports/topics` (agregado y sin nombres, docente acotado a sus grupos) y `recursoSugerido()` acotado a la materia del examen.
  - Un tema necesita 3 respuestas mínimo para reportarse; los metadatos son opcionales en el endpoint de preguntas.
  - **Desbloquea E2** (banco de 60 ítems) y cierra **K2**, **K3** y **K4**.
  - ⚠️ Queda vivo que los temas dependen de que el profesorado los escriba, y que la agregación no cubre sinónimos ni tildes. Si el piloto (E4) muestra dispersión, la salida es el catálogo.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §2 Metadatos curriculares · §3.6 nº 7
- [ ] 🟡 **D2a · Vigilar la dispersión de temas en el piloto** (lo deja abierto D2)
  - `topic` es opcional y texto libre: un centro que no etiquete ítems no verá nada por tema —ni en el diagnóstico ni en `/reports/topics`— y **no habrá ningún error que lo avise**.
  - La agregación tolera mayúsculas y espacios, **no sinónimos ni tildes**: «Fracciones» y «Fracciones equivalentes» siguen siendo dos temas.
  - Si el piloto (E4) muestra dispersión, la salida es el catálogo de temas por materia que se descartó en D2 por coste.
  - Fuente: `ESTADO_Y_PENDIENTES.md`, pendientes abiertos del 13/09/2026
- [x] 🔴 **D3 · Expiración de sesión** — ✅ 13/09/2026 · **inactividad real de 60 min, no expiración absoluta**
  - **Bajar `SANCTUM_TOKEN_EXPIRATION_MINUTES` a 60 habría sido un error.** Esa variable es expiración **absoluta desde que se emite el token**, y un examen admite hasta 300 minutos (`duration_minutes`): habría expulsado al alumno a mitad de la prueba, costándole el intento. El informe [758] no pide eso, pide 60 minutos **de inactividad**.
  - Implementado sobre `last_used_at`, que Sanctum ya mantiene: `SANCTUM_TOKEN_INACTIVITY_MINUTES=60`, con el tope absoluto donde estaba (12 h).
  - Va por `Sanctum::authenticateAccessTokensUsing()` y **no por un middleware**: el guard actualiza `last_used_at` justo después de validar, así que un middleware posterior no caducaría nunca a nadie.
  - ⚠️ **Para P2:** hay que fijar en Coolify las **dos** variables, `SANCTUM_TOKEN_EXPIRATION_MINUTES=720` y `SANCTUM_TOKEN_INACTIVITY_MINUTES=60`.
  - Cierra **K9** y **I-11**.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §9.3 · `ANALISIS_MODELO_DATOS_TFG.md` §10.2 nº 9
- [x] 🟡 **D4 · Aviso de «esta respuesta la genera una IA» ([397])** — ✅ 13/09/2026 · **campo en la respuesta**
  - `ai_notice` viaja en `POST /ai/tutor/chat` y en `GET /ai/tutor/diagnosis`. Se eligió el sistema y no el rótulo del frontend porque el compromiso de [397] es del sistema: con una app móvil o un segundo cliente, nadie tiene que acordarse de repetirlo.
  - El texto vive en `openai.tutor.notice` (`OPENAI_TUTOR_NOTICE`): es para alumnado de primaria y el tono se ajusta sin desplegar.
  - Cierra **K5** y deja **F7** listo para consumir.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §2 Aviso de IA e incidencias · §3.6 nº 8
- [x] 🟡 **D5 · Registro de incidencias del tutor IA** — ✅ 13/09/2026 · **tabla + métrica de plataforma**
  - Migración `2026_09_13_000003` (**ya aplicada** en Supabase): tabla `ai_tutor_incidents` con tipo (`pii`, `too_short`, `too_long`, `blocked_url`, `model_error`) y etapa (`chat`, `diagnosis`).
  - ⚠️ **No guarda el texto que provocó la incidencia.** Un registro de bloqueos por datos personales que almacenara el dato personal sería el fallo que pretende evitar. Hay test que lo comprueba.
  - **`GET /platform/ai-tutor-metrics`, solo superadmin**: ventana, totales, `validation_pass_rate` contra el umbral de 75, desglose por tipo/etapa/institución y serie diaria. Filtros `?from=`, `?to=`, `?institution_id=`. **Solo agregados** — ni un identificador de alumno sale de ahí.
  - Con esto el criterio de [173] pasa de frase del informe a número que se mira. Cierra **K6**.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §2 Aviso de IA e incidencias · §3.6 nº 6
- [x] **D6 · `PATCH /users/{id}/reset-password` no activa la cuenta** — ✅ 13/09/2026 · **se queda, pero la respuesta avisa**
  - La regla no se toca: activar exige que el titular defina contraseña desde el enlace, porque eso prueba que controla el buzón — y eso un administrador no puede acreditarlo en su nombre.
  - Lo que se corrige es la trampa de soporte: la respuesta devuelve `status`, `can_sign_in` y `activation_needed`, y el mensaje dice que hay que enviarle el enlace de activación.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §8
- [x] **D7 · `available-exams` muestra `max_attempts` al alumno** — ✅ 13/09/2026 · **confirmado, se queda**
  - Sin cambios de código. Con `max_attempts` y `submitted_count` el frontend puede pintar «intento 2 de 3».
  - No es información sensible: es una regla del examen que le aplica a quien la lee.
- [x] **D8 · Formalizar `ENABLE ROW LEVEL SECURITY` en una migración** — ✅ 13/09/2026 · **sí**
  - Migración `2026_09_13_000004` sobre **26 tablas**. Idempotente: en Supabase, donde ya estaba, no cambió nada — comprobado tras aplicarla.
  - Activar RLS **sin políticas** no afecta a la app (su rol es propietario y lo bypasea). Lo que cierra es la API REST automática de Supabase sobre la misma base. El aislamiento entre centros lo sigue haciendo `TenantScoped`.
  - **Quita una trampa:** `schema:dump-sql` ya recoge el RLS de la propia base, así que dejó de haber que repegar el bloque a mano tras cada regeneración del esquema.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §5, pendientes 31/07

---

## 2. Seguridad

- [x] 🔴 **S1 · Revisión sistemática de seguridad por rol, endpoint por endpoint** — ✅ 13/09/2026
  - Revisados los **122 endpoints** contra los cuatro roles, preguntando **qué ve cada uno cuando llega**, no solo si llega.
  - **1 hallazgo real, corregido: `/users` era una puerta paralela a `/students`.** Filtraba por institución pero no por asignación, así que un docente sin ninguna asignación listaba a todos los menores del centro con nombre y correo (`GET /users?user_type=student`) y abría la ficha de cualquiera (`GET /users/{id}`), mientras `/students` le devolvía cero. Acotado al alumnado alcanzable; el personal del centro sigue visible.
  - Verificado correcto el resto: `/students`, `/groups`, `/student-progress`, `/ai-recommendations`, analíticas por estudiante, los cinco reportes, intentos de examen, aislamiento entre instituciones y la frontera del superadministrador.
  - Queda blindado por `tests/Feature/Security/AlcancePorRolTest.php` (20 casos), que recorre la matriz entera.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §2 Revisión de alcance por rol
- [x] 🟡 **S5 · Analíticas por materia acotadas al docente** — ✅ 13/09/2026 · **el docente ve lo suyo, el admin el de todos**
  - `GET /analytics/subjects` devolvía el rendimiento de **todas** las materias del centro a cualquiera de los dos. Ahora el docente ve solo las que imparte (`materiasDelDocente()`); el administrador sigue viéndolas todas.
  - No era fuga de datos personales —son agregados sin nombres— pero le ponía delante el desempeño de las clases de sus colegas. Misma frontera que el resto del sistema.
  - Un docente sin asignaciones recibe lista vacía, igual que en `/students` o `/groups`.
- [x] 🟡 **S6 · Autoría para editar y borrar calendario y recursos** — ✅ 13/09/2026 · **son de quien los crea**
  - `PUT`/`DELETE` de `calendar-events` y `study-resources` no miraban `created_by`: cualquier docente reescribía o borraba lo de otro. Ahora cada entrada es de su autor.
  - **El administrador queda fuera de la regla**: responde por la institución y necesita poder ordenar el calendario o retirar un recurso cuando quien lo subió ya no está.
  - ⚠️ **Entradas sin autor** (`created_by` es nullable y queda en `NULL` al borrarse la cuenta que lo creó): **solo las toca el administrador**. Dejarlas abiertas a cualquier docente reabriría el agujero por la puerta de atrás.
  - La regla vive en el trait `ExigeAutoria`, para que un endpoint nuevo la herede en vez de reinventarla.
- [ ] 🔴 **S2 · Cambiar la contraseña temporal del superadmin en producción**
  - `POST /api/password/change`. Antes de que haya usuarios reales.
- [ ] 🔴 **S3 · Credencial de PostgreSQL en el historial de git**
  - [ ] Rotar esa contraseña si sigue viva en alguna base.
  - [ ] Decidir con el equipo si se limpia el historial (`git filter-repo`/BFG + push forzado a `Infil505/NeoEduCore-Back`; afecta a los otros proponentes).
- [x] 🟠 **S4 · Posible inyección de fórmulas en los CSV exportados** — ✅ 13/09/2026
  - **Confirmado y corregido.** Reproducido con un `full_name` igual a `=HYPERLINK("http://malo.example","clic")`: el CSV lo escribía tal cual. Ahora las celdas de texto que empiezan por `=`, `+`, `-`, `@`, tabulador o retorno de carro salen precedidas de un apóstrofo, y el XLSX las escribe con tipo texto explícito. Los números no se tocan, así que un `-3` sigue siendo un número.
  - Cubierto por `tests/Feature/Crud/ReportExportsTest.php`, por las dos ramas del dataset (nombre de alumno y título de examen).
  - Detalle original, para referencia:
  - `ReportExportService::streamCsv()` escribe con `fputcsv` los textos tal cual. Si un nombre de alumno, título de examen o materia empieza por `=`, `+`, `-` o `@`, Excel lo ejecuta como fórmula al abrir el CSV (p. ej. `=HYPERLINK(...)`).
  - Ninguna validación lo impide: `full_name` solo exige `string`, `min` y `max`.
  - Corrección habitual: anteponer `'` a esas celdas al exportar. Hacerlo junto con B1, que toca el mismo servicio.
  - Detectado el 10/09/2026 al revisar el servicio para anotar B1; no se ha comprobado con una petición real.

---

## 3. Producción y despliegue

- [ ] 🔴 **P1 · Desplegar el código en Coolify**
  - [ ] Recurso App (Octane + FrankenPHP)
  - [ ] Recurso Worker (`queue:work`) — 🔴 **imprescindible desde D1**: sin él ningún alumno pasa de las plantillas
  - [ ] Scheduled task (`schedule:run`)
  - Las migraciones ya están en producción; los cambios de permisos solo entran al desplegar.
  - Fuente: `DEPLOY_COOLIFY.md` §2–5
- [ ] 🔴 **P2 · Variables de entorno en Coolify** (el `.env` local no llega al servidor)
  - [ ] `CACHE_STORE` distinto de `array` — si no, ningún rate limiter es global
  - [ ] `QUEUE_CONNECTION=database` (no `sync`) — con `sync` la llamada a OpenAI vuelve dentro de la petición y D1 deja de tener sentido
  - [ ] `TRUSTED_PROXIES` — si no, todos los límites por IP agrupan a los usuarios y el sistema se autobloquea
  - [ ] `APP_NAME=NeoEduCore`
  - [ ] `APP_URL=https://<dominio real>` — y registrar la URL real en `DEPLOY_COOLIFY.md:44` (hoy `https://tu-dominio`)
  - [ ] `OPENAI_REQUEST_TIMEOUT=15`
  - [ ] `DB_STATEMENT_TIMEOUT_MS=15000`
  - [ ] `SANCTUM_TOKEN_EXPIRATION_MINUTES=720` **y** `SANCTUM_TOKEN_INACTIVITY_MINUTES=60` — las dos, según D3
- [ ] 🔴 **P3 · Cargar las asignaciones de docentes** (`POST /api/teacher-assignments`)
  - La tabla nace vacía a propósito: hasta entonces **ningún docente ve a ningún estudiante**.
- [ ] 🟠 **P4 · Cloudflare delante del dominio + sus rangos en `TRUSTED_PROXIES`, en el mismo cambio**
  - ⚠️ Lo primero sin lo segundo deja el sistema peor que ahora.
  - Fuente: `DEPLOY_COOLIFY.md` §9
- [ ] **P5 · Rate limiting en Traefik** como segunda capa
- [ ] 🟠 **P6 · Backups cifrados de la base de datos** — no hay script ni documentación (RNF de seguridad)
- [ ] 🟠 **P7 · Monitoreo y alertas de caída** — Sentry o equivalente (RNF de disponibilidad)
- [ ] **P8 · Documentar HTTPS/TLS** en `DEPLOY_COOLIFY.md` (lo resuelve Coolify, pero debe quedar escrito)

---

## 4. Mediciones

Dependen de P1–P2 salvo M4.

- [ ] 🔴 **M1 · Verificar que `$request->ip()` devuelve IPs reales** en producción y no la de Traefik
- [ ] **M2 · RTT real del contenedor a Supabase**
  - `psql "$DATABASE_URL" -c '\timing on' -c 'SELECT 1;'` desde el contenedor desplegado.
  - Fuente: `ANALISIS_CONCURRENCIA.md` §6.5
- [ ] **M3 · Prueba de carga contra el despliegue real con Octane** (valida «200 concurrentes»)
  - `k6 run -e BASE_URL=https://<host>/api -e VUS=50 -e DURATION=60s k6/exam_peak.js`
- [ ] 🟠 **M4 · Informe de cobertura ≥ 70 %**
  - `php artisan test --coverage --min=70` (requiere Xdebug o PCOV).

---

## 5. Frontend

Lo que el backend ya dejó listo y falta consumir, más los cambios que lo rompen.

### Funcionalidad nueva

- [ ] 🔴 **F1 · PDF de reportes** con gráficos de barras, líneas y pastel
  - Datos: `GET /reports/exams/{id}/summary` y `GET /reports/students/{id}/summary`.
  - `performance_levels` son categorías ordenadas: rampa de un solo tono, nunca verde/rojo.
  - Hasta que exista, [236] del informe sigue siendo parcialmente falsa.
- [ ] 🔴 **F2 · PDF de estrategias del tutor**
  - Datos: `GET /reports/students/me/strategies` y `GET /reports/students/{id}/strategies`.
- [ ] 🔴 **F3 · Panel de superadmin** — instituciones y sus administradores
- [ ] 🔴 **F4 · Pantalla de admin para asignar docentes** (`/api/teacher-assignments`)
- [ ] **F5 · Confirmación antes de borrar** materias/grupos/exámenes/usuarios, mostrando `exams_count` (los borrados cascadean a resultados de alumnos)
- [ ] **F6 · Saludo con el nombre del alumno en el tutor** (el backend ya no lo envía a OpenAI)
- [ ] **F7 · Aviso de IA en cada respuesta del tutor** — 🔓 listo para consumir: el backend lo manda en `data.ai_notice` (D4)
- [ ] 🟠 **F17 · Panel de métricas del tutor IA para el superadmin**
  - Datos: `GET /platform/ai-tutor-metrics` (ventana, totales, `validation_pass_rate`, por tipo, por etapa, por institución y serie diaria).
  - El umbral de [173] es 75 %: que se vea de un vistazo si se cumple.
  - Solo agregados: no hay ningún dato de alumno que mostrar.
- [ ] **F8 · Cargar el diagnóstico automáticamente al abrir el tutor** (`GET /ai/tutor/diagnosis`)
- [ ] **F9 · Contexto `exam_id` opcional en el chat del tutor**

### Adaptaciones a cambios que rompen

- [ ] 🔴 **F10 · Publicar/activar exámenes con `PATCH /exams/{id}/status`** — sin esto ningún examen sale de `draft`
- [ ] 🔴 **F11 · Vista del alumno con `GET /students/me/available-exams`** en vez de `GET /exams`
- [ ] 🔴 **F12 · Docente acotado a sus grupos asignados**
  - [ ] `GET /students`, `GET /groups` e informes devuelven solo lo asignado (403 fuera)
  - [ ] `POST/DELETE /groups/{id}/students` pasó a admin-only
  - [ ] Crear examen con grupo no asignado → 403 con `grupos_no_asignados`
- [ ] **F13 · Admin sin acceso a `/api/institutions`** → leer su institución por `GET /api/system/config`
- [ ] **F14 · Plantilla de carga masiva nueva** — columna **`aula`** obligatoria; fuera `grade`, `section`, `group_code`. Bajarla de `/api/students/bulk-upload/template`
- [ ] **F15 · Cuentas de carga masiva nacen inactivas** hasta que el dueño define su contraseña
- [ ] **F16 · `GET /ai-recommendations` ya no lista al docente las de exámenes ajenos**

---

## 6. Informe del TFG

> Buscar cada pasaje **por su texto**, no por número de párrafo: los índices están
> desfasados (equivalencias en `ANALISIS_MODELO_DATOS_TFG.md` §10.3).

### Correcciones críticas

- [ ] 🔴 **I-1 · Stack tecnológico** — `ANALISIS_MODELO_DATOS_TFG.md` §9.1
  - [ ] Quitar Node.js como capa de datos intermediaria
  - [ ] Quitar Next.js → React 19 + Vite + TypeScript
  - [ ] JWT → Laravel Sanctum
  - [ ] Render/Railway → DigitalOcean + Coolify + Supabase
- [ ] 🔴 **I-2 · Relación docente↔estudiante vía `teacher_assignments`** (§9.8.5)
- [ ] 🔴 **I-3 · Capítulo del tutor** — D1 se cerró por código, así que el capítulo puede describir el sistema tal cual: plantillas inmediatas al entregar + análisis del modelo al consultar resultados. Ya no hay que recortar la promesa

### Modelo de datos y diagramas

- [ ] 🟠 **I-4 · Incorporar al `.docx` los 16 diagramas de `DIAGRAMAS.md`** (su tabla final dice qué figura sustituye cada uno)
- [ ] 🟠 **I-5 · Diccionario de datos** (47 FK, 19 tablas de dominio = 15 entidades + 4 pivotes)
- [ ] 🟠 **I-6 · Añadir `AiChatSession`, `StudentSubject` y `TeacherAssignment` al diagrama de clases**
- [ ] 🟠 **I-7 · Nombrar los 4 roles reales** (superadmin, admin, teacher, student) y la frontera superadmin/admin (§9.6)
- [ ] 🟡 **I-8 · Justificar las 2 decisiones de diseño** de §4

### Requisitos no funcionales

- [ ] 🟡 **I-9 · Rendimiento:** ≤2 s y reporte de 1.000 en <5 s están medidos; «200 concurrentes» según M3
- [ ] **I-10 · Disponibilidad 99 %:** declarar que no se sostiene solo con la aplicación (requiere P4)
- [x] **I-11 · Expiración de sesión** — ✅ 13/09/2026: se cumple [758]. Merece una línea explicando que es inactividad y no expiración absoluta, y por qué (exámenes de hasta 300 min)

### Contradicciones internas — §10.1

- [ ] **C1** · Tutor «a estudiantes y docentes» [165][505] vs. docente sin mensajes individuales [173]
- [ ] **C2** · Revisión periódica docente del contenido [396] vs. docente no ve mensajes [173]
- [ ] **C3** · Recomendaciones por estudiante [737][739] vs. [173] — reformular como *conversación vs. recomendación*
- [ ] **C4** · React+Next.js [225][804] vs. React+TS sin SSR [296-297][417-418]
- [ ] **C5** · Node.js intermediario [419-420] vs. Laravel+PostgreSQL [228-229][415-416]
- [ ] **C6** · «Genera PDF y CSV» en presente [236] vs. requisito pendiente [732]
- [ ] **C7** · «Se generó reportes en PDF» [390] vs. [732-733]
- [ ] **C8** · Criterio 75 % automático [173] vs. revisión humana [396] — el 75 % ya es medible (D5); queda decidir qué dice el informe sobre la revisión humana

### Contradicciones con el sistema — §10.2

- [x] **K1** · Recomendaciones generadas por IA [122][222][255][737] — ✅ 13/09/2026: ❌ deja de ser contradicción, el sistema se ajustó al informe (D1)
- [x] **K2** · Ítems con tema/indicador/dificultad [171][222] — ✅ 13/09/2026: ❌ deja de ser contradicción, las columnas existen (D2)
- [x] **K3** · Personalización por área concreta («comprensión de lectura») [263][276] — ✅ 13/09/2026: el diagnóstico ya habla por tema (D2)
- [x] **K4** · «Recursos personalizados» (Figura 10) — ✅ 13/09/2026: el recurso sugerido se acota por materia, grado y dificultad (D2)
- [x] **K5** · Aviso de IA en cada intervención [397] — ✅ 13/09/2026: el sistema lo envía en `ai_notice` (D4)
- [x] **K6** · «Registrará incidencias» / 75 % [173] — ✅ 13/09/2026: hay tabla y el porcentaje se calcula (D5)
- [ ] **K7** · «2–4 oraciones» [173] vs. prompt de «máximo 4 párrafos»
- [ ] **K8** · «GPT-4 variante ligera» [222] → `gpt-4o-mini` (variante de GPT-4o)
- [x] **K9** · Sesión de 60 min [758] — ✅ 13/09/2026: el sistema caduca por inactividad a los 60 min (D3)
- [ ] **K10** · «OpenAPI 5.0» [771] → OpenAPI 3.0 (la 5.0 no existe)
- [ ] **K11** · Tres roles [720] → cuatro (ver I-7)

### Capítulos

- [ ] **Cap. 3 · Metodología** — Scrum e instrumentos de recolección
- [ ] **Cap. 5 · Conclusiones y recomendaciones**
- [ ] **Cap. 7 · Validación y resultados del piloto** — requiere E4
- [ ] **Cap. 8 · Discusión de resultados** — requiere E4
- [ ] **Cap. 9 · Aspectos éticos, legales y de privacidad** — datos de menores, tutor IA, Ley 8968
- [ ] **Cap. 10 · Trabajo futuro y escalabilidad**
- [ ] **Cap. 11 · Gestión del proyecto** — sprints, hitos, riesgos
- [ ] **Cap. 1 · Introducción** — completar (parcial)
- [ ] **Cap. 2 · Marco teórico** — completar (parcial)
- [ ] **Cap. 4 · Resultados** — completar (parcial)
- [ ] **Cap. 6 · Implementación** — completar y actualizar diagramas (parcial)

---

## 7. Entregables no-código

Fuente: `ESTADO_Y_PENDIENTES.md` §9.1

- [ ] 🔴 **E1 · Mockups / prototipo visual en Figma**
- [ ] 🔴 **E2 · Banco de ítems: mínimo 60 preguntas reales con metadatos** — 🔓 **desbloqueado el 13/09/2026**: las columnas `topic`, `indicator` y `difficulty` ya existen
- [ ] 🔴 **E3 · Acta del taller de co-diseño con docentes**
- [ ] 🔴 **E4 · Piloto con usuarios reales** (docentes y estudiantes) — requiere P1–P3
- [ ] 🟠 **E5 · Rúbricas para preguntas abiertas**
- [ ] 🟠 **E6 · Mini-guía para crear nuevos ítems**
- [ ] 🟠 **E7 · Manual de usuario básico en línea**
- [ ] 🟠 **E8 · Cronograma / bitácora del proyecto**
- [ ] **E9 · Anexo 1: encuestas a docentes y estudiantes** (diseñar y aplicar)
- [ ] **E10 · Anexo 2: guía de entrevistas semi-estructuradas** (diseñar y aplicar)

---

## 8. Mejoras opcionales

Sin urgencia; ninguna bloquea el TFG.

- [ ] **O1 · Notificar al estudiante que tiene un examen disponible** (hoy no hay evento ni correo) — `ESTADO_Y_PENDIENTES.md` §3.1
- [ ] **O2 · Adaptar el formato de la respuesta del tutor al estilo de aprendizaje** (hoy solo cambia el tono) — §3.2
- [ ] **O3 · Bajar el coste fijo del submit de 22 a ~12 queries** (~170 entregas/s). Perfilar antes; candidato: `recalcFromAttempts` a la cola — `ANALISIS_CONCURRENCIA.md` §5.5
- [ ] **O4 · Comando `schema:check-drift` para CI**
- [ ] **O5 · `exclude_student_user_ids` en `POST /bulk/reassign-group`**
- [ ] **O6 · Cerrar el residual de ~13 ms de temporización en `/password/forgot`**
- [ ] **O7 · Mover las llamadas a OpenAI a la cola** (solo si crece el volumen del tutor)
- [ ] **O8 · Relaciones Eloquent faltantes** (`belongsTo(Institution)` en 8 modelos, `hasMany` en `Institution` y `Subject`) — solo si el diagrama de clases se dibuja leyendo modelos — §3.5

---

## 9. Funcionalidad nueva del backend

Requisitos nuevos, anotados después de crear esta lista.

- [x] 🔴 **B2 · El sistema es de primaria (1.º–6.º, 6 a 12 años) y el tutor adapta el lenguaje al grado** — ✅ 13/09/2026
  - **Había tres rangos de grado escritos a mano y ninguno coincidía**: `config/academic.php` decía 6–12 («secundaria de Costa Rica»), `GroupController` validaba 6–12, `ExamController` 7–12 y los prompts del tutor hablaban de «primaria». Consecuencia real: **no se podía crear un examen de 6.º** aunque sí el grupo.
  - Ahora los tres salen de `config('academic.grade_min'/'grade_max')`, con **1–6** por defecto. Las factories también, así que los tests siguen al dominio en vez de fijar su propio rango.
  - **El tutor le escribe distinto a 1.º que a 6.º.** De las tres superficies, solo el chat sabía el grado —y como número suelto con «adapta el nivel de detalle»—; el diagnóstico y las recomendaciones post-examen ni lo recibían. Las tres reciben ahora una instrucción explícita por franja (1–2, 3–4, 5–6, y una conservadora si no se conoce el grado).
  - La **etapa** también estaba a mano en los tres prompts («un estudiante de primaria»): ahora sale de `config('academic.etapa')` = `primaria (6 a 12 años)`. Va la edad y no solo la etiqueta porque al modelo le dice más.
  - Los textos de las franjas viven en `config/openai.php` (`tutor.registro`): son texto pedagógico y los afina el profesorado, no el código. La lógica, en `App\Services\AI\RegistroPorGrado`.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §2 Primaria 1.º–6.º
- [ ] 🟠 **B2a · Decidir qué se hace con los datos fuera de rango** (lo deja abierto B2)
  - De los **65 estudiantes** de la base remota, solo **5 tienen grado o fecha de nacimiento** — y esos 5 tienen **15 y 16 años** (grados 10 y 11). Los otros 60 no tienen ninguno de los dos. Grupos en 11, exámenes en 10.
  - La validación solo actúa al escribir, así que nada se rompe; pero esas filas ya no describen un centro de primaria.
  - ¿Se limpian, se remapean o se dejan como datos de prueba hasta el piloto (E4)?

- [x] 🟠 **B1 · Exportar los reportes también en XLSX** (además de PDF y CSV) — ✅ 13/09/2026
  - Requisito fijado por el usuario el 10/09/2026: el sistema debe generar los reportes en PDF, CSV **y XLSX**.
  - Rutas nuevas, en paralelo a las `.csv`: `GET /reports/exams/{exam}/results.xlsx` y `GET /reports/students/{id}/history.xlsx`. Mismos permisos que su versión CSV (`assertCanAccessExam` y `findStudent`).
  - Se genera en el backend, igual que el CSV: es serialización del dataset, no presentación. `phpoffice/phpspreadsheet` ya está instalado (lo usa la carga masiva).
  - Plan técnico, ya revisado sobre `app/Services/Admin/ReportExportService.php`:
    - [x] Definir el dataset de cada reporte (cabeceras + filas) una sola vez y que CSV y XLSX lo consuman, para que no diverjan
    - [x] Escribir las celdas con **tipo explícito** (`setCellValueExplicit`): notas como número, `submitted_at` como fecha de Excel y el resto como texto. `setCellValue()` convierte en fórmula todo texto que empiece por `=`
    - [x] Tras escribir: `$book->disconnectWorksheets()`, porque bajo Octane el worker sobrevive a la petición
    - [x] A diferencia del CSV, PhpSpreadsheet arma el libro en memoria; con estos volúmenes son pocos MB, pero hay que dejarlo documentado
    - [x] Tests: tipo de contenido, cabeceras iguales al CSV, tipos de celda, texto con `=` guardado como texto, 403 para docente sin acceso, ruta en `ProtectedRoutesRequireAuthTest` y presupuesto de queries plano (`QueryBudgetTest`)
    - [x] Regenerar OpenAPI (`php artisan openapi:generate`) y Postman (`php postman/generate_postman_collection.php`)
    - [x] Actualizar `ESTADO_Y_PENDIENTES.md` §2 Reportes y §6 Referencia de endpoints
  - Implementado el 13/09/2026: 382 tests pasando (7 nuevos en `tests/Feature/Crud/ReportExportsTest.php`), 119 endpoints. Cierra también **S4**, que tocaba el mismo servicio.
  - Queda abierto de aquí:
    - [ ] **B1a · Zona horaria de los reportes.** Las fechas salen en **UTC** en los tres formatos. `institutions.settings.timezone` existe pero ningún reporte lo lee. Decidir si se convierte a la hora del centro (y entonces en CSV, XLSX y JSON a la vez, no en uno solo).
    - [ ] **B1b · Informe:** donde dice «PDF y CSV» ([236], [390], [732]) añadir XLSX. Ya está implementado, así que la frase puede ir en presente.
