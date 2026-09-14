# NeoEduCore — Checklist de pendientes

**Creada:** 10 de septiembre de 2026
**Base:** estado registrado al 08/08/2026 (último commit `7851f21`, 11/08/2026) — 375 tests, 117 endpoints

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
> estado en un desplegable y una hoja de resumen que calcula el avance. Se generó desde
> este fichero el 10/09/2026, pero **las dos copias no se sincronizan**: conviene marcar
> el avance en una sola.
>
> ⚠️ Lo que depende de producción (despliegue, contraseña del superadmin, asignaciones)
> figura como pendiente según el último registro. **Verificarlo antes de empezar**: pudo
> cambiar sin quedar anotado.

**Orden sugerido:** Decisiones D1–D3 → Seguridad S1 → Producción P1–P4 → Mediciones →
Informe. Frontend y entregables pueden avanzar en paralelo, salvo E2 (banco de ítems), que
espera a D2.

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
- [ ] 🔴 **D3 · Expiración de sesión: 60 min (informe) vs. 12 h (sistema)**
  - `config/sanctum.php:50` → `SANCTUM_TOKEN_EXPIRATION_MINUTES`, 720 por defecto.
  - Recomendado: bajar la variable (cuentas de menores en equipos compartidos) en vez de relajar el requisito.
  - Si se baja: ajustar `.env.example` y fijarlo en Coolify (P2).
  - Fuente: `ESTADO_Y_PENDIENTES.md` §9.3 · `ANALISIS_MODELO_DATOS_TFG.md` §10.2 nº 9
- [ ] 🟡 **D4 · Aviso de «esta respuesta la genera una IA» ([397])**
  - Campo en la respuesta de `POST /ai/tutor/chat` (hoy `{session_id, reply, message_count}`) **o** el informe lo atribuye a la interfaz.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §3.6 nº 8
- [ ] 🟡 **D5 · Registro de incidencias del tutor IA**
  - Hoy los bloqueos por PII o enlace no permitido solo dejan `Log::warning`.
  - Crear tabla/contador **o** retirar del informe el criterio «>75 % de mensajes que superen validación».
  - Fuente: `ESTADO_Y_PENDIENTES.md` §9.3 · §3.6 nº 6
- [ ] **D6 · `PATCH /users/{id}/reset-password` no activa la cuenta**
  - Coherente con «la activa su dueño», pero confunde a soporte. ¿Se deja, se activa o se documenta?
  - Fuente: `ESTADO_Y_PENDIENTES.md` §8
- [ ] **D7 · `available-exams` muestra `max_attempts` al alumno**
  - Se dejó para poder mostrar «intento 2 de 3». Confirmar.
- [ ] **D8 · ¿Formalizar `ENABLE ROW LEVEL SECURITY` en una migración?**
  - Hoy lo pone Supabase; un PostgreSQL que no sea Supabase no lo tendría.
  - Fuente: `ESTADO_Y_PENDIENTES.md` §5, pendientes 31/07

---

## 2. Seguridad

- [ ] 🔴 **S1 · Revisión sistemática de seguridad por rol, endpoint por endpoint**
  - Pregunta a responder en cada ruta: **qué campos ve cada rol**, no solo si llega.
  - Motivo: 5 hallazgos aparecieron por casualidad (G12, 05/08, 07/08, 08/08, 08/08 tarde) y ningún test existente los detectaba.
  - Herramienta: `/security-review` o `/code-review` sobre la rama.
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
  - [ ] `SANCTUM_TOKEN_EXPIRATION_MINUTES` según D3
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
- [ ] **F7 · Aviso de IA en cada respuesta del tutor** (según D4)
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
- [ ] **I-11 · Expiración de sesión** según D3

### Contradicciones internas — §10.1

- [ ] **C1** · Tutor «a estudiantes y docentes» [165][505] vs. docente sin mensajes individuales [173]
- [ ] **C2** · Revisión periódica docente del contenido [396] vs. docente no ve mensajes [173]
- [ ] **C3** · Recomendaciones por estudiante [737][739] vs. [173] — reformular como *conversación vs. recomendación*
- [ ] **C4** · React+Next.js [225][804] vs. React+TS sin SSR [296-297][417-418]
- [ ] **C5** · Node.js intermediario [419-420] vs. Laravel+PostgreSQL [228-229][415-416]
- [ ] **C6** · «Genera PDF y CSV» en presente [236] vs. requisito pendiente [732]
- [ ] **C7** · «Se generó reportes en PDF» [390] vs. [732-733]
- [ ] **C8** · Criterio 75 % automático [173] vs. revisión humana [396]

### Contradicciones con el sistema — §10.2

- [x] **K1** · Recomendaciones generadas por IA [122][222][255][737] — ✅ 13/09/2026: ❌ deja de ser contradicción, el sistema se ajustó al informe (D1)
- [x] **K2** · Ítems con tema/indicador/dificultad [171][222] — ✅ 13/09/2026: ❌ deja de ser contradicción, las columnas existen (D2)
- [x] **K3** · Personalización por área concreta («comprensión de lectura») [263][276] — ✅ 13/09/2026: el diagnóstico ya habla por tema (D2)
- [x] **K4** · «Recursos personalizados» (Figura 10) — ✅ 13/09/2026: el recurso sugerido se acota por materia, grado y dificultad (D2)
- [ ] **K5** · Aviso de IA en cada intervención [397] → según D4
- [ ] **K6** · «Registrará incidencias» / 75 % [173] → según D5
- [ ] **K7** · «2–4 oraciones» [173] vs. prompt de «máximo 4 párrafos»
- [ ] **K8** · «GPT-4 variante ligera» [222] → `gpt-4o-mini` (variante de GPT-4o)
- [ ] **K9** · Sesión de 60 min [758] → según D3
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
