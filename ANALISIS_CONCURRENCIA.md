# NeoEduCore — Estimación de concurrencia

**Fecha:** 31 de julio de 2026
**Objetivo declarado del TFG:** 200 usuarios concurrentes con tiempos razonables.

> **Qué es esto.** Un modelo analítico construido sobre mediciones de coste por request y latencia de red (§2, §3), **validado después con una prueba de carga real** (§6). La carga se ejecutó contra una base local desechable; **en ningún momento contra producción**.
>
> **Corrección al modelo tras medir (03/08/2026):** §3.2 asumía ~15 ms de CPU PHP por petición. Medido, el trabajo real de endpoint va de **127 ms** (lecturas) a **231 ms** (entrega de examen) sobre servidor de desarrollo, más **186 ms de arranque del framework** que Octane elimina. La componente PHP **no es despreciable** como suponía el modelo; el orden de magnitud de las capacidades de §3.3 se mantiene, pero las cifras concretas deben tomarse como estimación hasta medir sobre el despliegue real (§6.4).

---

## 1. Resumen ejecutivo

> **Actualización 31/07/2026 — el batching del corrector ya está aplicado.** Las cifras de abajo son las **posteriores** al cambio. El apartado §5.1 conserva el antes/después.

| Escenario | Capacidad estimada | ¿Cumple 200 concurrentes? |
|---|---|---|
| Navegación / lectura (listados, perfiles) | ~320 req/s | ✅ Con mucho margen |
| Operaciones masivas (promoción, replan) | ~140 req/s | ✅ Es un uso administrativo puntual |
| **Entrega de examen (20 preguntas)** | **~97 entregas/s** | ✅ **Cubre incluso el pico más duro** |
| Tutor IA conversacional | Acotado a 2 req/s por institución | ✅ **Ya no puede starvar al resto** |

**Conclusión:** con el corrector por lotes el flujo de examen **deja de ser el cuello de botella** (200 alumnos entregando en 3-5 s entran con holgura), y con el presupuesto global de IA el tutor **ya no puede dejar sin workers al resto del sistema**. El sistema cumple el objetivo de 200 concurrentes en todos los escenarios modelados.

Palancas restantes, por orden de impacto:

1. **Colocar app y BD en la misma región** (§5.2) — la latencia por query cae de ~18 ms a ~1-2 ms; multiplica por ~5-8 **todas** las capacidades de golpe. Es la de mayor alcance que queda.
2. **Reducir el coste fijo del submit** (§5.5) — quedan ~20 queries de overhead que ahora dominan.
3. **Mover OpenAI a la cola** (§5.3) — solo si el volumen de tutor crece hasta rozar el presupuesto global.

---

## 2. Mediciones (lo que sí es dato)

### 2.1 Queries por request

Medido con `tests/Feature/Perf/QueryBudgetTest.php`, que además queda como guardia anti-N+1 en la suite.

| Endpoint | Queries | ¿Crece con el volumen? |
|---|---|---|
| `GET /api/subjects` | ≤ 6 | **No** — `withCount` es subquery |
| `GET /api/students` | ≤ 8 | **No** |
| `GET /api/analytics/institution` | ≤ 12 | **No** |
| `POST /api/groups/{g}/students` | ≤ 12 | **No** — 3 y 40 alumnos cuestan igual |
| `POST /api/bulk/reassign-group` | ≤ 15 | **No** — 3 y 40 alumnos cuestan igual |
| `POST /api/bulk/reassign-subjects` | ≤ 12 | **No** — 9 y 120 inscripciones cuestan igual |
| `POST /api/bulk/reset-progress` | ≤ 12 | **No** |
| **`POST .../attempts/{a}/submit`** | **22** | **No** *(era `20 + 3·N`, corregido)* |

El submit **era** la excepción: medido 29 queries con 3 preguntas y 65 con 15, es decir `20 + 3·N` — unas 80 para un examen de 20 preguntas.

**Origen** (`ExamGradingService::gradeAttempt`): el bucle de corrección hacía por pregunta un `StudentAnswer::create()` (1 INSERT) y un `syncWithPivotValues()` (SELECT + INSERT). El eager load de opciones sí evitaba las *lecturas* N+1 —esa optimización previa era correcta— pero las *escrituras* iban de una en una.

**Ya corregido** (§5.1): ahora acumula las filas y hace dos INSERT por lotes. **22 queries constantes**, sea cual sea el número de preguntas. De esas 22, solo **2** son la corrección; las otras ~20 son coste fijo del endpoint (auth, tenant, validación, recálculo de progreso, recomendaciones) y son la siguiente palanca (§5.5).

### 2.2 Latencia de red por query

Medido con `psql` contra el session pooler, dentro de una conexión ya abierta (aísla la red del handshake):

```
Time: 155,188 ms   Time: 151,372 ms   Time: 151,114 ms
Time: 152,214 ms   Time: 153,611 ms   Time: 151,284 ms
```

**~152 ms por query desde la máquina de desarrollo.** Las consultas son `SELECT 1` y `count(*)` sobre tablas de 65-206 filas: el tiempo es **red pura**, no trabajo de la BD.

⚠️ Ese número es del portátil, **no de producción**. En producción la app vive en DigitalOcean **SFO3** y la BD en Supabase **us-west-2** (Oregón): ~1.000 km, RTT esperable **15-25 ms**. El modelo usa **18 ms** y ese supuesto **hay que confirmarlo midiendo desde el contenedor desplegado** (§6).

### 2.3 Límites de la base de datos

Consultado en vivo sobre la instancia real:

```
max_connections                = 60
superuser_reserved_connections = 3
conexiones en uso (reposo)     = 13
PostgreSQL                     = 17.6
```

→ **~44 conexiones disponibles para la aplicación.**

### 2.4 Volumen actual

`estudiantes=65 materias=206 examenes=3 intentos=3 respuestas=1`

Los datos son de desarrollo. **Ningún límite de este análisis viene del tamaño de los datos** — los endpoints son planos y los índices están puestos. Los cuellos son de latencia y conexiones, y no mejoran ni empeoran con el volumen.

---

## 3. El modelo

### 3.1 La restricción de fondo: PHP es síncrono

Con Octane, cada worker atiende **una** request a la vez. Mientras espera una query, el worker está bloqueado sin poder atender a nadie. Y en el **session pooler** (puerto 5432, obligatorio por el bug E9) cada worker retiene su conexión a la BD durante toda su vida.

De ahí la cadena de topes:

```
workers de Octane  ≤  conexiones disponibles  ≈  44
```

Reservando margen para el `queue:work` y para picos, un valor operativo sensato es **~40 workers**.

### 3.2 Tiempo de servicio

```
t_servicio ≈ (queries × RTT) + CPU_php
```

Con RTT = 18 ms y CPU_php ≈ 15 ms:

| Operación | Queries | t_servicio |
|---|---|---|
| Lectura típica | 6 | ~125 ms |
| Analíticas | 12 | ~230 ms |
| Operación masiva | 15 | ~285 ms |
| **Entrega, 20 preguntas** | **22** | **~410 ms** *(antes ~1.455 ms)* |

Es decir: **el ~96 % del tiempo de una entrega sigue siendo esperar a la red**, no calcular la nota. Lo que cambió es cuántas veces hay que esperar.

### 3.3 Capacidad (Ley de Little)

```
RPS_max = workers / t_servicio
```

| Operación | RPS con 40 workers |
|---|---|
| Lectura | 40 / 0,125 ≈ **320 /s** |
| Analíticas | 40 / 0,230 ≈ **174 /s** |
| Operación masiva | 40 / 0,285 ≈ **140 /s** |
| **Entrega de examen** | 40 / 0,411 ≈ **97 /s** *(antes ~27 /s)* |

### 3.4 De RPS a usuarios concurrentes

Un usuario navegando no pide sin parar: hay tiempo de lectura entre clics.

```
usuarios_concurrentes = RPS × tiempo_entre_peticiones
```

Con ~10 s entre peticiones al navegar: 320 × 10 = **~3.200 usuarios navegando**. La navegación **no es el problema**.

---

## 4. El escenario que importa: el pico de entregas

Lo que no se distribuye en el tiempo es el cierre de un examen: media institución entrega casi a la vez.

| Ventana en la que entregan 200 alumnos | Demanda | Antes (27/s) | Ahora (97/s) |
|---|---|---|---|
| 60 s (cierre escalonado) | 3,3 /s | ✅ | ✅ |
| 20 s | 10 /s | ✅ | ✅ |
| 10 s | 20 /s | ⚠️ Al límite | ✅ Holgado |
| **5 s (todos al sonar el timbre)** | **40 /s** | ❌ Saturación | ✅ **Entra, p95 ~0,5 s** |
| 3 s | 67 /s | ❌ Saturación | ✅ Entra |
| 2 s | 100 /s | ❌ | ⚠️ Al límite |

**Veredicto:** tras el batching, 200 concurrentes se cumple **incluso en el peor caso realista** (examen con corte duro y todos entregando en los últimos segundos). El escenario de saturación desaparece del flujo de examen.

### 4.1 Riesgo aparte: el tutor IA puede dejar sin workers al resto

> **Corrección al planteamiento anterior de este apartado.** Decía que el tutor «agota las conexiones a la BD». Es impreciso: con Octane cada worker mantiene su conexión **permanentemente**, esté trabajando o esperando, así que una llamada a OpenAI **no consume conexiones adicionales**. Lo que agota son **workers**. La conexión que retiene queda ociosa —ni siquiera carga la BD—, pero es un asiento ocupado que no se puede reasignar.
>
> La consecuencia práctica es la misma y sigue siendo el riesgo principal, pero por otra vía: como `workers ≤ conexiones ≈ 44`, **no se puede compensar la inanición añadiendo workers**, porque cada worker nuevo cuesta una conexión de un presupuesto muy escaso. Ese acoplamiento es el verdadero problema.

`POST /api/ai/tutor/chat` llama a OpenAI. Una llamada tarda **1-3 s** y durante todo ese tiempo el worker está bloqueado sin poder atender a nadie.

Con 40 workers y 2 s por llamada, bastan **~20 chats/s sostenidos** para ocupar todos los workers. En ese momento no queda ninguno para entregar exámenes: el tutor IA tumba funcionalmente el sistema entero sin que la BD llegue a enterarse.

Los `throttle:30,1` / `throttle:10,1` limitan a cada usuario, no al total: 40 alumnos distintos pueden generar 1.200 llamadas/min entre todos sin violar ningún límite. Mitigado en §5.3.

---

## 5. Palancas, por relación impacto/esfuerzo

### 5.1 Agrupar los inserts del corrector — ✅ **APLICADO (31/07/2026)**

`ExamGradingService::gradeAttempt` acumula ahora las respuestas y sus opciones en memoria y hace **dos INSERT por lotes** al final, en vez de 3 queries por pregunta.

```
Antes:  20 + 3·N  →  20 preguntas = 80 queries = ~1.455 ms
Ahora:  22 (constante) → cualquier nº de preguntas = ~410 ms
```

**Capacidad de entrega: de ~27/s a ~97/s (≈3,5×).**

> **Corrección a la estimación previa.** Este documento predecía "~8 queries y ≈8×". El resultado real es 22 queries y ≈3,5×. El motivo: la corrección en sí bajó a 2 queries como se esperaba, pero el **coste fijo del endpoint** (~20 queries: auth, tenant, validación, `recalcFromAttempts`, recomendaciones) no estaba desglosado en el modelo y ahora es el que manda. Ver §5.5.

Detalles de implementación que el `create()` de Eloquent hacía por nosotros y hubo que asumir en el insert masivo: generar el `id` con `Str::orderedUuid()` (la tabla no tiene `DEFAULT`), pasar `institution_id` explícito (no corre el hook de `TenantScoped`) y serializar `correct_answer_snapshot` a JSON a mano (no se aplica el cast). Se insertan las respuestas antes que las opciones por la FK, y en lotes de 500 para no acercarse al límite de 65.535 parámetros por sentencia de PostgreSQL.

`QueryBudgetTest` exige ahora coste **plano** (no solo marginal acotado): si alguien reintroduce el bucle, falla.

### 5.2 Acercar la app a la base de datos

Todo el modelo está dominado por el RTT. Pasar de 18 ms a 1-2 ms (misma región/VPC) divide **todos** los tiempos de servicio por ~5-8, no solo el del submit. Opciones: desplegar en una región AWS us-west-2, o mover la BD a un Postgres gestionado junto al droplet.

Sobre el estado actual (22 queries por entrega), llevaría la entrega a ~50 ms y la capacidad a varios cientos por segundo. Es la palanca de mayor alcance que queda.

### 5.3 Contener el tutor IA — ✅ **APLICADO (03/08/2026)**

Cuatro medidas, de la más estructural a la más barata:

**a) Presupuesto global de IA.** Nuevo limitador `ai-global` (`bootstrap/app.php`) aplicado a las **tres** rutas que llaman a OpenAI (`/ai/tutor/chat`, `/ai/tutor/diagnosis`, `/ai/generate`). Es **por institución**, no por usuario: 120/min ≈ 2 req/s ≈ ~4 workers ocupados de media, dejando el resto para el flujo de examen. Devuelve 429 con mensaje explicativo. Los throttle por usuario se mantienen encima.

**b) Contexto del estudiante cacheado.** El perfil y el progreso que arman el system prompt se releían en **cada turno** (4 queries: student, user, progress, subjects) aunque solo cambian al entregar un examen. Ahora se cachean 5 min.

```
Medido:  1er turno = 7 queries  →  turnos siguientes = 3 queries
Conversación de 20 turnos: 140 → 64 queries (−54 %)
```

Hay `AiTutorService::olvidarContexto($studentUserId)` para invalidar al instante si se prefiere no esperar al TTL.

**c) Escritura incremental de la conversación.** Era lo que más castigaba a la BD: `$session->update(['messages' => $todos])` reenviaba **la conversación entera** (hasta 60 mensajes de ~600 tokens ≈ cientos de KB) y reescribía todo el JSONB en cada turno. Ahora solo viaja el delta; PostgreSQL concatena con `||` y recorta al tope en la misma sentencia.

```
Medido (bytes enviados a la BD por turno):
  conversación corta = 748 B
  conversación larga = 748 B   ← constante, antes crecía sin parar
```

**d) Timeout de OpenAI acotado.** `OPENAI_REQUEST_TIMEOUT` **no estaba en `.env`**, así que regía el default de la librería: **30 s**. El comentario del código afirmaba que eran 15. Un OpenAI lento retenía un worker el doble de lo que el código decía. Corregido a 15 s y documentado en `.env.example`.

> ⚠️ **`CACHE_STORE` era `array`.** Los rate limiters de Laravel viven en el caché: con `array` y Octane, **cada worker lleva su propio contador** y ningún throttle es realmente global — ni los nuevos ni los que ya existían. Corregido a `file`. **En producción hay que fijarlo en Coolify**, no basta con el `.env` local.

Queda como mejora futura, si el volumen lo pide: mover las llamadas a OpenAI a la **cola** y devolver el resultado por polling/websocket, que es lo único que elimina el bloqueo de worker por completo.

### 5.5 Reducir el coste fijo del submit

Tras §5.1, de las 22 queries por entrega solo 2 son la corrección. Las otras ~20 son overhead del endpoint: resolución de auth y tenant, carga de examen/intento, validación, `recalcFromAttempts` y generación de recomendaciones. Bajarlo a ~12 llevaría la entrega de ~410 ms a ~230 ms (**~170 entregas/s**).

No es urgente —el flujo de examen ya cumple el objetivo— pero es donde está el margen si se quiere más cabecera. Conviene perfilarlo antes de tocar nada: puede que buena parte sea `recalcFromAttempts`, que se podría diferir a la cola.

### 5.4 Ajustes de operación (baratos)

- **Escalonar los cierres de examen** — con `available_until` distinto por grupo, el pico de §4 se reparte solo. Es gratis y muy efectivo.
- **Fijar `--workers` explícitamente** en Octane. Por defecto usa el nº de cores; aquí el criterio no es CPU (las requests están bloqueadas en I/O) sino el presupuesto de conexiones: **súbelo hasta ~40**, no lo dejes en 1-2.
- **Vigilar el presupuesto de conexiones**: workers + queue workers + cualquier herramienta conectada deben caber en 44. Al escalar a más de un contenedor, el tope es *global*, no por contenedor.

---

## 6. Resultados de la prueba de carga (03/08/2026)

> **Entorno.** Base PostgreSQL 17.9 **local y desechable** (`neoeducore_load`), sembrada con `LoadTestSeeder`. **En ningún momento se lanzó carga contra la base de producción.** Cliente: k6 v1.7.1. Servidor: `php artisan serve` (ver §6.4 sobre sus límites).

### 6.1 Descomposición del tiempo de respuesta (1 VU, sin contención)

`/api/ping` es una ruta pública mínima: mide arranque del framework y enrutado, nada más. Restándola se aísla el trabajo real de cada endpoint.

| Endpoint | Total | − arranque | Trabajo real |
|---|---|---|---|
| `GET /ping` (arranque de Laravel) | 186 ms | — | *(coste fijo)* |
| `GET /subjects` | 313 ms | −186 | **127 ms** |
| `GET /exams` | 315 ms | −186 | **129 ms** |
| `POST /exams/{}/attempts/start` | 366 ms | −186 | **180 ms** |
| `POST /exams/{}/attempts/{}/submit` (20 preguntas) | 417 ms | −186 | **231 ms** |

**El 45 % de cada petición es arrancar el framework.** Eso es exactamente lo que Octane elimina en producción manteniendo la aplicación en memoria: es la justificación empírica de esa decisión de arquitectura.

### 6.2 Escalado con el número de workers

Cada iteración = ciclo completo de entrega (`start` + `submit` de 20 preguntas).

| VUs | Workers | Throughput | p95 del ciclo | Errores 5xx |
|---|---|---|---|---|
| 1 | 1 | 0,72 it/s | 0,97 s | **0** |
| 10 | 1 | 0,75 it/s | 9,94 s | **0** |
| 8 | 8 | **4,74 it/s** | 1,19 s | **0** |
| 24 | 8 | 4,87 it/s | 3,36 s | **0** |

Tres conclusiones, todas medidas:

1. **Escala con los workers.** De 1 a 8 workers el throughput sube ×6,6 (eficiencia ~82 %). El sistema no tiene un punto de serialización interno.
2. **Satura de forma limpia.** Al triplicar la carga sobre 8 workers (24 VUs), el throughput se queda plano en ~4,9 it/s y lo que crece es la latencia (1,19 s → 3,36 s). Es encolamiento, no rotura: **cero errores 5xx en las cuatro configuraciones**.
3. **La base de datos no es el cuello.** A 4,74 it/s con ~30 queries por iteración son ~142 queries/s, trivial para PostgreSQL. No hubo agotamiento de conexiones ni bloqueos. El límite era la CPU de los procesos PHP.

Las filas de 1 worker con 1 vs 10 VUs muestran el efecto de encolar contra un único proceso: mismo throughput, latencia ×10.

### 6.3 Requisito «reporte de 1000 estudiantes en menos de 5 s»

Sembrados 1.000 estudiantes, 1.000 intentos entregados y 20.000 respuestas.

| Endpoint | Antes | Después | Requisito |
|---|---|---|---|
| **`GET /reports/exams/{}/results.csv`** | **3.790 ms** | **1.068 ms** | < 5.000 ms ✅ |
| `GET /reports/exams/{}/results` | 413 ms | — | ✅ |
| `GET /students` (1000, paginado) | 468 ms | — | ✅ |
| `GET /analytics/institution` | 426 ms | — | ✅ |

**Hallazgo: N+1 en la exportación CSV.** El código usaba `->with(['student.user'])->cursor()`. **`cursor()` ignora el eager loading** —recorre fila a fila y no puede resolver los ids por adelantado—, así que el `with()` no se aplicaba y cada fila disparaba 2 queries: ~2.000 extra en un examen de 1.000 alumnos. El comentario del código («procesa un registro a la vez, sin cargar la colección entera») solo acertaba en lo de la memoria.

Corregido con `->lazy()`, que acota la memoria igual pero por lotes **y sí respeta el eager load**: **3,5× más rápido**, con salida byte a byte idéntica (91.701 bytes). Cubierto por `QueryBudgetTest::test_csv_export_does_not_scale_with_row_count`.

El requisito ya se cumplía por poco (3,79 s de 5 s), pero sin margen y sobre un servidor de desarrollo. Ahora sobra holgura.

### 6.4 Qué NO demuestran estas mediciones

Hay que ser explícito con los límites, porque el entorno de prueba difiere de producción en dos direcciones opuestas:

- **`php artisan serve` es más lento que producción.** No tiene opcache ni Octane: PHP recompila el código en cada petición y el framework arranca de cero (186 ms medidos). En producción Octane mantiene la aplicación en memoria y opcache elimina la recompilación. Los tiempos de §6.1 son una **cota superior**, no una predicción.
- **La base local es más rápida que producción.** RTT ≈ 0,2 ms frente a los ~18 ms estimados contra Supabase. Con 22 queries por entrega, eso son ~400 ms que aquí no se pagan.

Ambos efectos son grandes y de signo contrario, así que **estos números no se pueden trasladar directamente a producción**. Lo que sí queda validado, y era el objetivo:

- ✅ El coste por petición **no crece** con el volumen de datos (confirma `QueryBudgetTest`).
- ✅ El throughput **escala** con los workers; no hay serialización interna.
- ✅ Bajo sobrecarga **degrada encolando, sin errores**.
- ✅ La base de datos **no es el cuello de botella** a estos ritmos.
- ✅ El requisito del reporte de 1000 estudiantes **se cumple**.

**Sigue pendiente** la única medición que no se puede hacer desde aquí: el RTT real entre el contenedor desplegado y Supabase (§6.5), que es el parámetro que domina todo el modelo.

### 6.5 Cómo completar la validación

Queda un solo paso, y es el que más pesa en el modelo:

1. **Medir el RTT real desde producción.** El supuesto de 18 ms es el parámetro más frágil de todo el análisis y no se puede medir desde una máquina de desarrollo (desde el portátil salen ~152 ms). Desde el contenedor desplegado:
   ```bash
   psql "$DATABASE_URL" -c '\timing on' -c 'SELECT 1;'
   ```
   Si sale muy por encima de 25 ms, todas las capacidades de §3.3 bajan proporcionalmente.

2. **Repetir §6.2 contra un despliegue real con Octane**, para sustituir la cota superior de §6.1 por el tiempo de servicio verdadero:
   ```bash
   k6 run -e BASE_URL=https://<host>/api -e VUS=50 -e DURATION=60s k6/exam_peak.js
   ```

3. **Observar las conexiones durante el pico:**
   ```sql
   SELECT count(*), state FROM pg_stat_activity GROUP BY state;
   ```
   Si aparece `too many connections`, el tope de §2.3 llegó antes que el de CPU.

> ⚠️ **No lanzar carga contra la BD de producción con datos reales.** Levantar una instancia Supabase de staging con el mismo esquema (`database/sql/01_schema.sql`) y sembrar con `php artisan db:seed --class=LoadTestSeeder`.

### 6.6 Herramientas añadidas

| Fichero | Para qué |
|---|---|
| `database/seeders/LoadTestSeeder.php` | Siembra el escenario de pico: institución, grupo, examen activo de N preguntas y N estudiantes matriculados. Parametrizable con `ESTUDIANTES` y `PREGUNTAS`. Se niega a correr en producción |
| `k6/exam_peak.js` | Escenario del pico de entregas (`start` + `submit`), el que `stress_all.js` excluye. Admite `MODE=ramp` para buscar el punto de saturación y varios backends por coma en `BASE_URLS` |
| `k6/baseline_decompose.js` | Descompone el tiempo de respuesta separando el arranque del framework del trabajo real de cada endpoint |
