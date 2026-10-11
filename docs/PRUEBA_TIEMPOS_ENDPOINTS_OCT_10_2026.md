# Tiempos de respuesta de la API — 10/10/2026

Objetivo fijado por el usuario: **ninguna respuesta pasa de 2 s**, dentro de lo posible.

## Cómo se midió

- `composer dev` (127.0.0.1:8000, un solo hilo) contra la Supabase remota de pruebas. Tokens `qa-temporal` (borrados al terminar) de director, docente, alumno y superadmin.
- Se recorrieron los **67 GET** con cada rol permitido (123 combinaciones endpoint×rol), 2 pasadas (fría y caliente). Los 67 GET sin token devuelven 401.
- **Constantes del entorno** (medidas con `select 1` desde PHP): cada consulta a Supabase cuesta **0,43 s** (estable); abrir conexión, 1,5–2,3 s. El arranque de PHP en esta máquina varía entre 0,17 y 0,6 s según la carga (el servidor de lenguaje PHP de VS Code consumía CPU durante las pruebas).
- Por eso el tiempo HTTP de un endpoint ≈ arranque + 0,43 s × (token + consultas de la aplicación): **con token + 3 consultas queda en ~1,7–2,3 s**. El número de consultas es lo único que no cambia entre mediciones y es lo que se vigila en los tests.

## Estado inicial (HTTP, mejor de dos pasadas, red tranquila)

58 de 123 combinaciones pasaban de 2 s. Las peores: `dashboard/staff-overview` 10–11 s (sin `?include=`; 19–28 consultas), `ai/tutor/diagnosis` 7,6 s en frío, `dashboard/student-overview` 6 s en frío, `platform/ai-tutor-metrics` 3,9 s (10 consultas), y casi todos los detalles (`exams/{id}`, `calendar-events/{id}`, `study-resources/{id}`, `ai-recommendations/{id}`…) y reportes entre 2,3 y 3,9 s (5–8 consultas cada uno).

## Qué se cambió (menos viajes a la base, mismo JSON)

| Técnica | Dónde |
|---|---|
| Relaciones «a uno» con `LEFT JOIN` en la misma consulta (`RelacionesEnLinea`, ya existía; ahora admite clave primaria distinta de `id`) | detalle de aviso, recurso, examen e intento; recomendaciones IA (lista y detalle); progreso; resultados de examen; historial del alumno; panel del personal (exámenes, calendario, alumnado, recursos); exportaciones CSV/XLSX; grupos |
| Preguntas con sus opciones en una consulta (`json_agg`, `PreguntasEnLinea`) | `GET /exams/{id}`, `GET /exams/{id}/questions` |
| Respuestas del intento con pregunta, opciones y opciones marcadas en una consulta (`RespuestasEnLinea`) | `GET /exams/{e}/attempts/{a}` |
| Binding de ruta + relaciones fundidos en una sola consulta (el controlador recibe el id como texto; el patrón UUID de la ruta sigue dando 404 a ids malformados) | los mismos detalles |
| Totales con funciones de ventana en las mismas filas que el `LIMIT` | analítica del alumno, resumen del alumno |
| Cifras por materia como subselects (`Subject::conCifras`) | analítica por materia, panel del personal |
| Comprobación de «¿este docente alcanza a este alumno?» dentro de la consulta del alumno (`Student::conUsuario($id, $alcance)`) | detalle del alumno, historial, resumen, estrategias, analítica |
| Materia desde el catálogo en caché (`CatalogoMaterias`) en vez de una consulta | reportes, estrategias, diagnóstico, recomendaciones |
| Métricas de plataforma: 8 consultas → 3 | `platform/ai-tutor-metrics` |

## Resultado (consultas de la aplicación por petición, caché caliente, en los 123 casos)

| Consultas | Combinaciones |
|---|---|
| 0 | 19 |
| 1 | 37 |
| 2 | 44 |
| 3 | 20 |
| ≥ 4 | **3** |

**120 de 123 quedan en ≤3 consultas** (≈ 1,7–2,3 s en este entorno remoto, y décimas de segundo con la base en el mismo servidor). Antes, 58 pasaban de 2 s.

### Los 3 que siguen por encima (excepciones, con su porqué)

| Endpoint | Consultas | Antes | Por qué sigue |
|---|---|---|---|
| `dashboard/staff-overview` **sin `?include=`** (admin / docente) | 10 / 12 (frío 16 / 14) | 19–28 | Es el modo «todo»: junta 11 secciones. **El front nunca lo usa**: siempre pide `?include=` y cada sección suelta queda en ≤3 consultas (probado, admin y docente). |
| `dashboard/student-overview` (alumno) | 7 (frío 12) | 9–14 | Panel agregado: alumno, aulas, progreso, exámenes disponibles, recomendaciones, sesiones del tutor y materias; todas independientes y de datos que cambian. Bajarlo más exigiría una caché por alumno con invalidación en cada entrega/revisión/matrícula: decisión pendiente (riesgo de panel viejo). |

## Tests persistentes

`tests/Feature/Perf/ConsultasEnLineaTest.php` (16 tests): presupuesto de consultas por endpoint; equivalencia campo a campo con `with()` de lo traído en línea; el alumno no recibe `is_correct` ni `correct_answer_text`; las listas de columnas unidas (`Exam::COLUMNAS`, `Question::COLUMNAS`, `Student::COLUMNAS`…) coinciden con las tablas reales; alcance del docente (403 sin asignación, 404 si no existe); ids sin forma de UUID → 404. Suite completa: **1003 tests verdes**.

La suite de ataques cazó una regresión a mitad de trabajo (ids mal formados daban 500 en las rutas que reciben el id como texto): corregida con patrones de ruta UUID.

## No verificado

- Escrituras (POST/PUT/PATCH/DELETE) no se ejecutaron en vivo, solo la suite.
- El barrido HTTP final salió **más lento que el inicial incluso en endpoints que no se tocaron** (p. ej. `notifications/unread-count` 1,0 → 2,6 s) por la carga de la máquina de desarrollo; no sirve para comparar. Los tiempos fiables son los de la sección inicial y los conteos de consultas.
- Un solo cliente secuencial: no se midió concurrencia.
- `ai/tutor/sessions/{id}` no se probó con una sesión real (la base no tiene sesiones del tutor).
- Producción (base junto a la API, opcache, Redis) no se midió; se espera muy por debajo de 2 s.

---

# Escrituras en vivo (10/10/2026, tarde)

Se ejecutaron **en vivo contra el servidor** las escrituras de los tres roles, con datos propios (`QA-…`, correos `pdelvin74+qa-…@gmail.com`, autorizados por el usuario) y borrado final: 0 filas QA, 0 tokens temporales, 243 alumnos y 248 usuarios intactos. Cubierto: materias, aulas, altas (`/register`), asignaciones, estado de usuarios y alumnos, matrícula en aulas, `system/config`, examen completo (crear, preguntas, editar, publicar, activar), avisos, recursos, intento del alumno (iniciar, pausar, reanudar, entregar), recomendaciones, tutor, revisión de respuesta, progreso, matrícula individual, acciones masivas (`bulk/*`), contraseñas (cambiar, olvidé, verificar, restablecer), superadmin (centro, director, alta/baja), cargas masivas de alumnos y docentes y borrados.

## Resultado funcional
Todo respondió con el código esperado. Comportamientos de seguridad comprobados: desactivar o resetear la clave de un usuario **revoca sus tokens** (401); una clave temporal bloquea todo salvo cambiarla (403); un docente sin asignación no puede generar, resetear ni revisar (403); no se borra al único director de un centro (409); un examen no borrador no se borra (409); las cargas masivas están limitadas (429 a la segunda en un minuto) y rechazan secciones inexistentes con mensaje claro.

## Hallazgos
1. **Bug — `DELETE /subjects/{id}` da 500** si la materia tiene recomendaciones de IA: `ai_recommendations.subject_id` tiene FK `ON DELETE SET NULL` pero la columna es `NOT NULL` (el esquema se contradice). Además, borrar una materia **arrastra en cascada** sus exámenes, progreso, matrículas y asignaciones sin ninguna confirmación. Decisión pendiente: bloquear con 409 si tiene exámenes, progreso o recomendaciones (propuesta) o permitir la cascada y arreglar la columna.
2. **Dev:** con `composer dev` no hay Reverb levantado y el trabajo de difusión (`BroadcastException: cURL error 7 … localhost port 80`) queda en `failed_jobs` (1 fila). No afecta a las respuestas.
3. `DELETE /groups/{id}` con alumnado dentro se permite (borra las matrículas). Confirmar que es lo deseado.

## Tiempos de las escrituras
Casi todas pasan de 2 s en esta máquina (2–5 s las simples; 5–8 s las que encolan trabajo o recalculan). Las más lentas: `recommendations/regenerate` 18 s, `student-answers/{id}/review` 18 s, `ai/tutor/chat` 13 s (OpenAI sin créditos: respuesta de reserva), entregar el examen 11 s, `reports/exams/{id}/analysis` en frío 11,5 s, `analysis/ai` 7,4 s, crear examen 7,7 s, `exams/{id}/status` 7,5 s, `bulk/reassign-group` 6,6–8,5 s. **No se contaron consultas de las escrituras** (solo tiempo, con la máquina cargada); es el siguiente paso para aplicarles el mismo presupuesto.

---

# Recorte de las escrituras (10/10/2026, noche)

Mismo método que con las lecturas: contar consultas por petición (en proceso, sin ruido de red) y quitar viajes a la base sin cambiar lo que se responde. Con la base remota cada viaje ≈ 0,4 s; las transacciones suman dos más (BEGIN y COMMIT).

| Escritura | Antes | Ahora |
|---|---|---|
| Entregar el examen | 16 | 6 |
| Regenerar recomendaciones | 18 | 8 |
| Revisar una respuesta | 15 | 6 |
| Crear pregunta (con opciones) | 7 | 2 |
| Editar / borrar pregunta | 5 / 5 | 2 / 2 |
| Crear examen | 11 | 7 (la primera tras cambiar el catálogo) |
| Editar examen | 6 | 3 |
| Activar examen (calendario + avisos) | 12 | 7 |
| Crear aviso / recurso | 7 / 8 | 4 / 3 |
| Editar aviso / recurso | 6 / 6 | 3 / 3 |
| Matricular / dar de baja en un aula | 6 / 5 | 1 / 1 |
| Progreso del alumno (upsert) | 9 | 3 |
| Matrícula individual en una materia | 7 | 3 |
| Asignación docente | 7 | 5 |
| Pausar / reanudar intento | 5 / 4 | 2 / 2 |

**38 de 54 escrituras quedan en ≤3 consultas** (antes 21). Siguen por encima: `ai/tutor/chat` (11, espera a OpenAI; existe el modo `async`), `bulk/reassign-group` (9), `reports/exams/{id}/analysis` (10 en frío; 1 con caché), `recommendations/regenerate` (8, espera a OpenAI), activar examen (7: dos avisos a la cola) y entregar (6: cuatro sentencias y la transacción).

## Qué se hizo
- Respuestas armadas con lo que ya se sabe (materia y aulas del catálogo en caché, autor = quien escribe) en vez de recargar relaciones; sin `fresh()` tras `save()`.
- Una sentencia SQL atómica (CTE) en lugar de varias: pregunta + opciones, respuestas + opciones + nota del intento, matrícula en aula + recuento, publicación del calendario, recálculo de progreso + media del alumno.
- Comprobaciones de existencia y alcance contra catálogos en caché o dentro de la consulta del alumno (`MateriaDelCentro`, `CatalogoGrupos`, `AjustesDelCentro`).
- Nueva área de caché `MAPAS` (materias y aulas) separada de `CATALOGO`, que se invalida con cada examen.

Tests: `tests/Feature/Perf/EscriturasEnLineaTest.php` (12). Suite completa: 1015 verdes.
