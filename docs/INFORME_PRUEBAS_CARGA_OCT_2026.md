# Informe de pruebas de carga — 12/10/2026

**Requisito:** que como mínimo **200 alumnos** puedan usar la plataforma a la vez.
**Meta de latencia:** p95 de cualquier petición < 5 s, sin errores 5xx.

**Resultado:** con la base de datos actual (PostgreSQL gestionado de DigitalOcean, NYC3) el requisito se **cumple**, también en el pico. Con la base anterior (Supabase, plan gratuito, us-west-2) **no se cumplía**: colapsaba a partir de 25–50 alumnos.

---

## 1. Qué se midió

Dos perfiles de carga, con [k6](https://k6.io) lanzado desde un PC en México contra `https://neoeducore.api.eduregistro.com`. Cada alumno virtual usa su propia cuenta (`alumno{n}@carga.test`).

| | **Carga suave** (`k6/concurrencia.js`) | **Carga extrema** (`k6/estres_200.js`) |
|---|---|---|
| Qué simula | Sesiones sostenidas que van creciendo | El **pico**: los 200 alumnos entran juntos y presentan el examen |
| Forma | Escalones de 10 → 25 → 50 → 100 → 150 → 200 alumnos, 60 s cada uno | Los 200 entran en 30 s y se mantienen 3 min |
| Login | Previo (en lotes de 40 por minuto), no se mide dentro de los escalones | **Dentro** de la prueba: es parte del pico |
| Cada alumno | Ve el panel, 2 lecturas al azar y presenta el examen **una vez** | Login, panel, inicia el examen, contesta (20–60 s) y entrega; luego sigue navegando |
| Carga media | ~10,6 peticiones/s | ~26 peticiones/s |
| Duración | ~6 min de escalones (+4 min de login previo) | ~4 min |

Datos sembrados con `LoadTestSeeder`: una institución (`LOAD001`), 200 alumnos, un docente, un admin y **un examen de 20 preguntas** verdadero/falso.

---

## 2. Con Supabase (plan gratuito, session pooler, us-west-2)

### 2.1 Carga suave

**Noche del 11–12/10** (resultado guardado en las notas del proyecto):

| Alumnos | p95 | |
|---|---|---|
| 10 | 2,8 s | ✅ |
| 25 | 6,4 s | ❌ |
| 50 | 36 s, con 233 respuestas 5xx | ❌ colapso |

k6 abortó a los 4 min por más de 20 % de fallos. 40 logins simultáneos tardaron 12,6 s de media.

**12/10 13:24–13:32** (8 min 14 s; abortó al 68 % por superar el 20 % de fallos):

| Alumnos | Media | Máxima | p95 | Meta < 5 s |
|---|---|---|---|---|
| 10 | 1,48 s | 3,24 s | 2,46 s | ✅ |
| 25 | 3,18 s | 8,48 s | 6,38 s | ❌ |
| 50 | 11,7 s | 16,7 s | 15,1 s | ❌ |
| 100 | 12,9 s | **36,96 s** | 35,2 s | ❌ (200 respuestas 5xx) |
| 150 | — | — | — | no llegó a medirse |
| 200 | — | — | — | no llegó a medirse |

Global: 989 peticiones, media 6,72 s, mediana 1,94 s, máxima 36,96 s, **20,2 % de peticiones fallidas**. Un lote de 40 logins tardó 9,6 s.

Capacidad real con esta base: **unos 10–15 alumnos** por debajo de 5 s.

### 2.2 Carga extrema

**No se ejecutó**: el sistema ya colapsaba con 50 alumnos en la carga suave, así que no tenía sentido lanzar el pico de 200. Solo existe un ensayo previo con 20 alumnos (panel 4,9 s, iniciar examen 6 s, 0 errores), que ya **rozaba la meta** (el panel al límite y el inicio del examen por encima).

### 2.3 Por qué fallaba

El log de la App (peticiones lentas, 19:32 UTC) lo midió directamente:

| Parte de la petición | Tiempo |
|---|---|
| PHP (la aplicación) | ~40 ms |
| Base de datos | **2.000–2.300 ms** |
| Consultas por petición | 8–10 (~250 ms cada una) |

El 98 % del tiempo era **esperar a la base**: cada consulta cruzaba la distancia hasta Supabase. Cada petición ocupaba un worker ~2 s, y con pocos workers (uno por núcleo) las peticiones se apilaban en cola hasta 37 s. Los 5xx no eran excepciones de Laravel: el log de la App no tenía ningún `ERROR`, y el contenedor no se reinició. Eran del proxy cuando la cola se desbordaba.

---

## 3. Con la base actual (PostgreSQL gestionado de DigitalOcean, NYC3)

Mismo código y mismos scripts. Cambió solo la base de datos (más tarde, la corrección de un error de memoria, ver §4).

### 3.1 Carga suave

**Primera pasada, 14:33–14:44** (10 min 25 s; completó los 6 escalones):

| Alumnos | Media | Máxima | p95 | Meta < 5 s |
|---|---|---|---|---|
| 10 | 224 ms | 979 ms | 406 ms | ✅ |
| 25 | 174 ms | 713 ms | 311 ms | ✅ |
| 50 | 170 ms | 655 ms | 312 ms | ✅ |
| 100 | 146 ms | 332 ms | 226 ms | ✅ |
| 150 | 213 ms | 1,37 s | 794 ms | ✅ |
| 200 | 187 ms | 965 ms | 354 ms | ✅ |

Global: 6.601 peticiones, media 190 ms, mediana 149 ms, máxima 1,37 s. **20 respuestas 5xx (0,3 %)** y 198 de 199 entregas correctas. Lote de 40 logins: 2,5 s.

**Segunda pasada tras corregir la fuga de memoria, 15:15–15:25** (10 min 23 s):

| Alumnos | Media | Máxima | p95 | Meta < 5 s |
|---|---|---|---|---|
| 10 | 232 ms | 746 ms | 431 ms | ✅ |
| 25 | 167 ms | 528 ms | 301 ms | ✅ |
| 50 | 140 ms | 319 ms | 225 ms | ✅ |
| 100 | 130 ms | 265 ms | 198 ms | ✅ |
| 150 | 136 ms | 389 ms | 213 ms | ✅ |
| 200 | 265 ms | 1,82 s | 1,23 s | ✅ |

Global: 6.574 peticiones, media 194 ms, mediana 136 ms, máxima 1,82 s, **0 respuestas 5xx**, 0 % de fallos, **200 de 200 entregas correctas**. Lote de 40 logins: 2,7 s.

### 3.2 Carga extrema (el pico de 200)

Se lanzó tres veces. **Las dos primeras no valen como pico** y se incluyen para que quede constancia:

| Pasada | Hora | Logins correctos | Respuestas 429 | p95 global | Máxima |
|---|---|---|---|---|---|
| 1 | 15:37 | 30,2 % (200 de 662) | 462 | 884 ms | 2,17 s |
| 2 | 15:42 | 29,7 % (200 de 673) | 473 | 2,46 s | 3,39 s |
| **3 (válida)** | **16:00** | **100 % (200 de 200)** | **0** | **439 ms** | **1,01 s** |

En las pasadas 1 y 2 los alumnos **no entraron todos en 30 s**: los límites de peticiones por IP (los 200 alumnos salen de una sola IP) dejaban pasar unos 60 logins por minuto y el resto recibía 429. No fue fallo del servidor. Se atribuye a los topes `RATE_LIMIT_LOGIN_IP_PER_MINUTE` y `RATE_LIMIT_API_PER_MINUTE` (este último cuenta por IP las peticiones sin sesión, login incluido); al subir los topes por IP, los 200 entraron a la vez (pasada 3). La atribución exacta a cada uno de los dos no se aisló con una prueba por separado.

**Resultado de la pasada válida** (16:00–16:04, 4 min 15 s):

| Medida | Media | Máxima | p95 | Meta < 5 s |
|---|---|---|---|---|
| Todas las peticiones | 181 ms | 1,01 s | 439 ms | ✅ |
| Login | 418 ms | 1,01 s | 719 ms | ✅ |
| Panel | 303 ms | 1,01 s | 563 ms | ✅ |
| Lecturas | 122 ms | 415 ms | 157 ms | ✅ |
| Iniciar examen | 306 ms | 932 ms | 574 ms | ✅ |
| Entregar examen | 198 ms | 460 ms | 298 ms | ✅ |

6.703 peticiones (~26/s), **0 respuestas 5xx, 0 % de fallos**, 200 de 200 logins y 200 de 200 entregas.

---

## 4. Comparación y causas

| | Supabase | DigitalOcean |
|---|---|---|
| Máxima espera (carga suave) | 36,96 s | 1,82 s |
| Alumnos con p95 < 5 s | ~10 | **200** |
| Peticiones fallidas | 20,2 % | 0 % |
| Lote de 40 logins | 9,6 s | 2,7 s |
| Carga extrema (200 a la vez) | no ejecutada | **superada** |

Lo que lo explica, en orden de impacto:

1. **La base de datos junto al servidor.** Cada consulta bajó de ~250 ms a unos pocos milisegundos. Es el cambio que permitió pasar de ~10 a 200 alumnos.
2. **Una fuga de memoria en el código** (detectada gracias a la prueba): el middleware `RegistraPeticionesLentas` registraba un oyente `DB::listen` en **cada petición**. Con Octane, el despachador de eventos vive tanto como el worker, y cada oyente acumulaba las consultas de todas las peticiones siguientes. Los workers agotaban los 128 MB y morían con un 500: el log de la App registró unas diez excepciones «Allowed memory size exhausted» en esa pasada, con las que se explican los 5xx de 3.1 (el número exacto de 5xx no se contrastó uno a uno). Corregido: un solo oyente por worker, con test de regresión (`PeticionesLentasTest`).
3. **Una consulta menos de la cuenta:** `GET /students/me/attempts` pasó de 4 a 2 consultas (examen y materia unidos en la misma consulta).
4. **Los límites de peticiones por IP**, que hubo que subir para la prueba del pico (ver §6).

---

## 5. Limitaciones de las pruebas (leer antes de citar los números)

- **Una sola pasada por configuración.** No se repitió cada prueba varias veces; hay algo de ruido (por ejemplo, el escalón de 200 tuvo p95 1,23 s en una pasada y 354 ms en la otra).
- **Las latencias incluyen la red del PC que lanza k6**: el mínimo de ~85–100 ms es el viaje desde México hasta el servidor. El tiempo dentro del servidor es menor.
- **Los alumnos entraron en 30 s, no en 1 s.** Un docente que activa el examen y 200 alumnos que pulsan «iniciar» en el mismo segundo es una condición algo más dura que la medida.
- **Un solo examen de 20 preguntas verdadero/falso.** Exámenes más largos, con más tipos de pregunta o con IA activada en el momento de entregar, no se midieron.
- **Todo desde una sola IP**, que es el caso real de un colegio detrás de un NAT, pero hizo falta tocar los límites.
- **No se conocen los núcleos del droplet ni el número de workers** de Octane (`OCTANE_WORKERS` sin fijar = uno por núcleo).
- La base de DigitalOcean tiene **`max_connections = 25`** (una por worker más el worker de colas): es el techo para subir workers.

---

## 6. Pendientes que deja la prueba

1. **Límites de peticiones.** Para el pico se subieron temporalmente `RATE_LIMIT_LOGIN_IP_PER_MINUTE` (60) y `RATE_LIMIT_API_PER_MINUTE` (120) a 10.000. Hay que **devolverlos a un valor razonable**. Propuesta para 200 alumnos + 20 docentes + admin + superadmin, todos desde la misma IP: login por IP **500**, resto sin cambios. El tope `api` mezcla en un solo número el límite por usuario con sesión y el de IP sin sesión (el login lo gasta); separarlos sería un cambio pequeño de código, aplazado por decisión del equipo.
2. **Presupuesto de IA** (`RATE_LIMIT_AI_GLOBAL_PER_MINUTE`, 120 por institución): cada llamada a OpenAI ocupa un worker 1–15 s. Con pocos workers puede bloquear la entrega de exámenes; depende del número de núcleos del droplet.
3. **Fijar `OCTANE_WORKERS`** conociendo los núcleos y respetando las 25 conexiones de la base.
4. **Cambiar las contraseñas** de las dos bases (pasaron por el chat de trabajo).
5. **Limpiar los datos de prueba del seeder** (ver §7).

---

## 7. Limpiar solo los datos del seeder

Los datos del `LoadTestSeeder` están todos bajo la institución `LOAD001`. Hay otra institución de prueba (`EULOGIO2026`) y los superadmins, que **no deben tocarse**.

⚠️ **Trampa verificada:** en las dos bases, `users.institution_id` tiene `ON DELETE SET NULL` (las otras 21 tablas son `CASCADE`). Si se borra solo la institución, sus 202 usuarios **no se borran**: quedan sin institución, y como el superadmin se define precisamente por no tener institución, serían cuentas huérfanas con la contraseña conocida de la prueba.

Procedimiento correcto: apuntar primero los usuarios, borrar la institución y luego esos usuarios, todo en una transacción:

```sql
BEGIN;
CREATE TEMP TABLE _ids ON COMMIT DROP AS
  SELECT id FROM users WHERE institution_id = (SELECT id FROM institutions WHERE code = 'LOAD001');
DELETE FROM institutions WHERE code = 'LOAD001';   -- cascada: alumnos, examen, intentos, respuestas…
DELETE FROM users WHERE id IN (SELECT id FROM _ids);
-- revisar los conteos y entonces: COMMIT;   (o ROLLBACK;)
```

**Simulación hecha el 12/10/2026** (dentro de una transacción con `ROLLBACK`, sin cambios persistentes):

| | DigitalOcean | Supabase |
|---|---|---|
| Antes | 204 usuarios · 840 intentos · 16.800 respuestas | 205 usuarios · 50 intentos · 1.000 respuestas |
| Tras el procedimiento | **2 usuarios** (1 de `EULOGIO2026`, 1 superadmin) · 0 intentos | **3 usuarios** (1 de `EULOGIO2026`, 2 superadmins) · 0 intentos |
| Tras el `ROLLBACK` | idéntico al «antes» | idéntico al «antes» |

La institución `EULOGIO2026` y los usuarios sin institución quedaron intactos en las dos bases. Si el borrado se hiciera sin el paso de los usuarios, habría 202 huérfanos (comprobado en la misma simulación).
