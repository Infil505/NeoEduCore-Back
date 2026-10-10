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
