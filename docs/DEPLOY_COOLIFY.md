# Despliegue en DigitalOcean + Coolify

Guía para desplegar NeoEduCore (Laravel + Octane + FrankenPHP) y aguantar picos de ~200 usuarios concurrentes, usando solo recursos gratuitos/incluidos.

---

## 1. Droplet
- Crear el droplet **en la misma región que Supabase** (`us-west-2` → en DO eso es **SFO3 / San Francisco**). Esto baja la latencia por query de ~100 ms a ~1-2 ms. Es la mejora más grande.
- Instalar Coolify en el droplet (script oficial).

## 2. Recurso "App" (servidor HTTP)
- Tipo: **Dockerfile** (Coolify lo detecta; usa el `Dockerfile` del repo).
- Puerto interno: **8100** (no el 8000: lo usa el panel de Coolify). Se cambia con `OCTANE_PORT`; si lo
  cambias, el puerto del recurso en Coolify y el de **Domains** tienen que coincidir.
- Octane sobre FrankenPHP mantiene la app en memoria → alta concurrencia con pocas conexiones a BD.

## 3. Recurso "Worker" (cola de correos)
- Otro recurso en Coolify con **la misma imagen**, pero comando:
  ```
  php artisan queue:work --queue=default,correos --tries=3 --max-time=3600 --max-jobs=500 --memory=128
  ```
- Mantiene el envío de correos (reset, alta masiva) en segundo plano. Coolify lo reinicia si cae.
- `--max-jobs` y `--memory` reciclan el proceso antes de que acumule memoria; Coolify lo vuelve a levantar.
- Variable `OCTANE_WORKERS` en el recurso App: número de workers de la API (vacío = uno por núcleo).
  Ver `.env.example`.

## 3b. Recurso "WebSocket" (Reverb) — instancia propia
El aviso «examen activado» sin recargar usa Laravel Reverb. Es un **tercer recurso en Coolify**, con
la misma imagen y otro comando, independiente de la API y del worker:

```
php artisan reverb:start --host=0.0.0.0 --port=8180 --no-interaction
```

- **Si tu Coolify no tiene campo de comando** (recurso Dockerfile): pon la variable
  `CONTAINER_ROLE=websocket` (Worker: `CONTAINER_ROLE=worker`; App: nada). El entrypoint sustituye el
  comando por el de arriba. Además, en ese recurso: *Ports Exposes* `8180`, vaciar *Pre/Post-deployment*
  (las migraciones solo en la App) y quitar las *Custom Docker options* de ejemplo.
- **Puerto interno:** 8180. Coolify lo reinicia si cae; la API y el worker no se enteran.
- **Si Reverb está caído no se cae nada.** El aviso en vivo se pierde y el alumno ve el examen al
  recargar o en su notificación.
- Las **tres instancias** (App, Worker, WebSocket) son la misma imagen y comparten el mismo conjunto de
  variables `REVERB_*`, `APP_KEY` y `FRONTEND_URL`.

### Cómo trabajan juntas
```
 navegador ──wss://ws.neoeducore.eduregistro.com──▶ [WebSocket :8180]
                                              ▲
 docente activa examen ─▶ [App :8100] ─cola─▶ [Worker] ──HTTP interno──┘  (publica el evento)
```
La App encola el evento, el Worker lo publica en Reverb y Reverb lo reparte a los navegadores.

### Exponerlo (dominio del recurso WebSocket)
El navegador se conecta **directo** al WebSocket, y desde una página `https` solo admite `wss://`.
En el recurso WebSocket, en **Domains**: `https://ws.neoeducore.eduregistro.com:8180`. Coolify (Traefik) pone el
TLS; el contenedor escucha en claro.

### Variables (en Coolify, no en el repo; iguales en los tres recursos)
```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=neoeducore
REVERB_APP_KEY=<cadena larga y aleatoria>      # el frontend la usa para conectarse
REVERB_APP_SECRET=<cadena larga y aleatoria>   # SOLO en el servidor, nunca en el frontend
REVERB_HOST=ws.neoeducore.eduregistro.com                  # dominio público (lo que usa el navegador)
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0                     # dónde escucha el proceso (dentro del contenedor)
REVERB_SERVER_PORT=8180
FRONTEND_URL=https://neoeducore.eduregistro.com    # Reverb solo acepta conexiones desde este host

# Opcional pero recomendado en App y Worker: publicar por la red interna de Docker en vez de
# salir al dominio público y volver a entrar por Traefik. <nombre> es el nombre interno del recurso
# WebSocket en Coolify (aparece en su pestaña de red/UUID).
REVERB_PUBLISH_HOST=<nombre-interno-del-recurso-websocket>
REVERB_PUBLISH_PORT=8180
REVERB_PUBLISH_SCHEME=http
```
Generar las dos claves: `php -r "echo bin2hex(random_bytes(24));"`. Sin `REVERB_PUBLISH_*` el
Worker publica por `REVERB_HOST` (el dominio público): funciona igual, con un salto más.

### Comprobar que funciona
1. Log del recurso WebSocket: `Starting server on 0.0.0.0:8180`.
2. Activar un examen con una cuenta de docente y ver `examen.activado` llegar a un alumno con la
   página abierta, sin recargar.
3. Si no llega: ¿el Worker corre (`queue:work`)? ¿`REVERB_HOST` es el dominio público y no
   `localhost`? ¿`FRONTEND_URL` coincide con el origen del frontend? ¿`REVERB_PUBLISH_HOST` resuelve
   desde el Worker?

### Límites
- **Una sola réplica del WebSocket.** Con varias, un alumno conectado a una no recibiría lo
  publicado en otra. Para escalar hay que activar `REVERB_SCALING_ENABLED=true` (necesita Redis, que
  de todos modos ya se usa para `CACHE_STORE`).
- La App sí puede tener varias réplicas: no abre ningún WebSocket.

## 4. Scheduler de Laravel
**Es imprescindible**: es lo que abre y cierra los exámenes por fecha (`exams:sincronizar-estados`,
cada minuto) y limpia los tokens caducados. Sin él, el docente tiene que abrir cada examen a mano.

- **Ya corre solo dentro del Worker** (`CONTAINER_ROLE=worker`): el entrypoint lanza
  `php artisan schedule:work` en segundo plano y lo relanza si cae. No hace falta crear nada en
  Coolify. Se desactiva con `RUN_SCHEDULER=false` si se prefiere ejecutarlo en otro sitio.
- ⚠️ Si el Worker se configuró con un **comando personalizado** en vez de `CONTAINER_ROLE=worker`,
  el entrypoint no se entera y el scheduler NO arranca: usar `CONTAINER_ROLE=worker`, o crear en
  Coolify → Scheduled Tasks (cada minuto) `php artisan schedule:run`.
- Con un solo Worker no hay solapes. Con varios, dejar `RUN_SCHEDULER=true` solo en uno (el
  comando es idempotente, pero el aviso a los alumnos no debe duplicarse).
- Comprobar que corre: `php artisan schedule:list` y, tras la hora de apertura de un examen de
  prueba, que pasa a `active` en un minuto.

## 5. Paso de despliegue: migraciones
- En Coolify, comando **post-deploy** (o "pre-start"):
  ```
  php artisan migrate --force
  ```
- No se corren dentro del Dockerfile a propósito (el build no debe tocar la BD).

## 6. Variables de entorno (en Coolify, no en el repo)
```
APP_NAME=NeoEduCore        # sale en el ASUNTO y el cuerpo de los correos;
                          # con el valor por defecto llegan diciendo "Laravel"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # php artisan key:generate --show
APP_URL=https://tu-dominio

# Supabase: POOLER EN MODO SESSION (5432), no transaction (6543)
DB_CONNECTION=pgsql
DB_HOST=aws-0-us-west-2.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.xxxxxxxx
DB_PASSWORD=...

# Proxies de confianza. SIN ESTO, $request->ip() devuelve la IP de Traefik y
# TODOS los limites por IP agrupan a los usuarios en un unico cubo: el sistema
# se autobloquea sin que nadie lo ataque. Los rangos privados cubren Traefik.
TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16

# Corta consultas HTTP que pasen de este tiempo (ms). No afecta a migraciones ni cola.
DB_STATEMENT_TIMEOUT_MS=15000

QUEUE_CONNECTION=database
# CACHE_STORE: usar `redis` (recurso Redis en Coolify + REDIS_HOST/REDIS_PORT/REDIS_PASSWORD).
# La API y el worker son contenedores distintos y la caché por centro (`TenantCache`:
# materias, aulas, docentes, asignaciones, configuración, avisos del alumnado) se
# invalida desde cualquiera de los dos, así que el almacén tiene que ser COMPARTIDO.
#  - `file` NO sirve en producción: cada contenedor tendría su propia caché y una
#    invalidación hecha en el worker no llegaría a la API (datos viejos hasta el TTL).
#  - `database` NO está preparado: ninguna migración crea la tabla `cache`, y además
#    cada lectura sería una consulta a la misma base que se quiere aliviar.
#  - `array` desactiva los límites de peticiones entre workers (ver más abajo).
CACHE_STORE=redis
SESSION_DRIVER=database

# Correo: SOLO Resend (API HTTPS). No hay SMTP ni se puede activar: `MAIL_MAILER=smtp` da
# «Mailer [smtp] is not defined». DigitalOcean bloquea los puertos 25/465/587 (comprobado:
# `fsockopen smtp.gmail.com:587` → timed out) y un SMTP bloqueado cuelga al worker.
MAIL_MAILER=resend
RESEND_KEY=...
MAIL_FROM_ADDRESS=no-reply@neoeducore.eduregistro.com   # dominio verificado en Resend
# Si falta RESEND_KEY o el mailer no es resend, al arrancar se escribe un `critical` en el log.

OPENAI_API_KEY=...
OPENAI_REQUEST_TIMEOUT=15     # sin esto rige el default de 30 s y un worker
                              # se queda bloqueado el doble de tiempo

# Para el pico de logins simultáneos: bcrypt más barato (sigue seguro)
BCRYPT_ROUNDS=10
```

### Variables que deben ser IGUALES en varias instancias
Las plantillas por instancia están en `deploy/coolify/*.env.example` (los `.env` reales de esa
carpeta están en `.gitignore`). Lo que se repite, y por qué:

| Variables | App | Worker | WebSocket | Por qué |
|---|:-:|:-:|:-:|---|
| `APP_KEY`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST/PORT/SCHEME`, `FRONTEND_URL` | ✔ | ✔ | ✔ | Firman y publican los mismos eventos; si difieren, el WebSocket rechaza o no entrega |
| `DB_*`, `REDIS_*`, `CACHE_STORE=redis` | ✔ | ✔ | — | Misma base y misma caché compartida |
| `MAIL_MAILER=resend`, `RESEND_KEY`, `MAIL_FROM_ADDRESS` | ✔ | ✔ | — | El Worker envía; la App también puede enviar |
| `EXAM_GRACE_SECONDS`, `EXAM_MAX_PAUSE_SECONDS`, `EXAM_ADECUACION_*`, `EXAM_AUTO_SUBMIT_DELAY_SECONDS` | ✔ | ✔ | — | La App calcula el plazo al enviar; el Worker (scheduler) entrega solo los intentos vencidos con **el mismo cálculo**. Si difieren, el servidor entregaría antes o después de lo que ve el alumno |
| `OPENAI_*` (modelo, topes, límites diarios), `AI_LINK_CHECK` | ✔ | ✔ | — | El tutor asíncrono y el análisis de IA corren en el Worker |
| `RUN_SCHEDULER=true` | — | ✔ (uno solo) | — | Abre/cierra exámenes y entrega intentos vencidos |

Solo en la App: `OCTANE_*`, `RATE_LIMIT_*`, `SANCTUM_*`, `SESSION_LIFETIME`, `TRUSTED_PROXIES`, `PAGINATION_*`.

### Opcionales — todas tienen un valor por defecto sensato

Añadidas el 08/08/2026 al sacar del código los valores operativos. **No hace falta
definirlas**: si se omiten rige el defecto, que es exactamente el comportamiento
anterior. Se listan porque son lo que un operador querrá tocar sin desplegar.

```
# --- Coste del tutor IA -----------------------------------------------------
# Lo primero que se ajusta si sube la factura. OPENAI_HISTORY_MESSAGES es el que
# más pesa: cada mensaje del historial viaja como contexto en TODAS las
# peticiones siguientes, así que su efecto sobre el gasto es multiplicativo.
OPENAI_MODEL=gpt-4o-mini
OPENAI_MAX_TOKENS=600
OPENAI_HISTORY_MESSAGES=20
OPENAI_STORED_MESSAGES=60
OPENAI_CONTEXT_TTL=300

# --- Límites de peticiones por minuto ---------------------------------------
# Dependen de CACHE_STORE: con `array` dejan de ser globales bajo Octane.
RATE_LIMIT_PASSWORD_PER_MINUTE=5
RATE_LIMIT_BULK_UPLOAD_PER_MINUTE=3
RATE_LIMIT_BULK_OPS_PER_MINUTE=10
RATE_LIMIT_AI_CHAT_PER_MINUTE=30
RATE_LIMIT_AI_GENERATE_PER_MINUTE=20

# --- Capacidad --------------------------------------------------------------
# Subirlos no es gratis: la carga masiva procesa el archivo entero dentro de una
# transacción, así que el coste es memoria del worker y duración del bloqueo.
BULK_MAX_ROWS=5000
BULK_MAX_MB=5
PAGINATION_DEFAULT=20
PAGINATION_REPORTS=50

# --- Dominio académico ------------------------------------------------------
# El sistema es de PRIMARIA: grados 1-6. Las secciones A-D son de COSTA RICA.
# Solo hay que tocarlos para otro país o un centro con más secciones.
ACADEMIC_GRADE_MIN=1
ACADEMIC_GRADE_MAX=6
ACADEMIC_SECTIONS=A,B,C,D
EXAM_GRACE_SECONDS=30

# ⚠️ NO son un parámetro de rendimiento: son TIEMPO ADICIONAL AL QUE UN
# ESTUDIANTE TIENE DERECHO por su adecuación curricular. Bajarlos por error
# reduce ese derecho en silencio y el sistema no lo va a cuestionar.
EXAM_ADECUACION_ACCESO=1.25
EXAM_ADECUACION_EVALUACION=1.50
```

> ⚠️ **`APP_URL` no es opcional aunque lo parezca.** Todos los enlaces de correo
> —activación de cuenta y recuperación de contraseña— se arman con `url()`, es
> decir con `APP_URL`. Con el valor por defecto (`http://localhost`) los correos
> salen con enlaces inservibles y el fallo no da ningún error: el correo llega,
> pero el enlace no lleva a ninguna parte.

## 7. Presupuesto de conexiones a Supabase
- El **session pooler (5432)** mantiene una conexión por worker. Con Octane, el contenedor usa pocas (worker threads comparten). Sumá: app + worker dedicado ≤ límite del plan Supabase.
- Si en producción escalás a varias instancias, vigilá ese total. El transaction pooler (6543) NO sirve aquí (rompe prepared statements con PDO — ver E9 en ESTADO_Y_PENDIENTES.md).

## 8. Notas
- `BCRYPT_ROUNDS=10` solo para el evento/piloto si esperás muchos logins a la vez; podés volver a 12 después.
- La imagen NO incluye `.env` (lo inyecta Coolify) ni `vendor` (se instala en el build).
- **Primer arranque, en este orden** (desde el 08/08/2026 el alta cambió):
  1. `php artisan superadmin:create --send-setup-link --email=... --name="..."` — es el operador de la plataforma y **ninguna ruta de API lo crea**. Sin él nadie puede dar de alta instituciones.
  2. El superadmin crea la institución (`POST /api/institutions`) y su administrador (`POST /api/institutions/{id}/admins`).
  3. El admin del centro da de alta docentes y estudiantes, crea los grupos y **asigna los docentes** (`POST /api/teacher-assignments`). ⚠️ La tabla nace vacía a propósito: hasta que existan asignaciones, **ningún docente ve a ningún estudiante**.
- La carga masiva de estudiantes exige la columna **`aula`** con el `group_code` de un grupo ya creado: el archivo no crea aulas. Bajar la plantilla actualizada de `/api/students/bulk-upload/template`.

## 9. Si se pone Cloudflare delante

Es la medida anti-DDoS con mejor relación coste/beneficio: absorbe el tráfico
volumétrico, que **no se puede parar desde la aplicación** —si llegan 10 Gbps, el
droplet se satura antes de que Laravel vea nada—. Pero hay que hacer un cambio a
la vez, o rompe el rate limiting:

1. Poner el dominio en Cloudflare (plan gratuito basta).
2. **Añadir los rangos de Cloudflare a `TRUSTED_PROXIES`**, sin quitar los
   privados: la cadena pasa a ser cliente → Cloudflare → Traefik → app, y la
   cabecera llega como `X-Forwarded-For: <cliente>, <cloudflare>`. Symfony la
   recorre de derecha a izquierda saltándose los proxies de confianza; si los de
   Cloudflare no están, se detiene en su IP y la toma por la del cliente — con lo
   que **todo el tráfico vuelve a agruparse en un solo cubo**. Rangos vigentes en
   <https://www.cloudflare.com/ips/>.
3. Comprobar en producción que `$request->ip()` devuelve IPs reales antes de dar
   el cambio por bueno.

El paso 2 no es opcional ni cosmético: sin él, el paso 1 deja el sistema peor que
antes.


## 10. Pendiente en Coolify: poner en marcha las tres instancias (actualizado el 11/10/2026)

El código ya está hecho. Es el mismo repositorio y la misma imagen para los tres recursos; solo
cambia el comando. En este orden:

1. **Redesplegar la App** (el entrypoint ya no arranca Reverb; solo cachea la configuración).
2. **Crear el recurso WebSocket**: mismo repositorio y Dockerfile, comando
   `php artisan reverb:start --host=0.0.0.0 --port=8180 --no-interaction`, dominio
   `https://ws.neoeducore.eduregistro.com:8180` (crear antes el registro DNS hacia el droplet).
3. **Variables `REVERB_*`, `APP_KEY`, `FRONTEND_URL` y conexión a la BD/Redis en los tres recursos**
   (§3b). Reiniciar los tres después.
4. **Pasarle al frontend** `REVERB_APP_KEY`, `ws.neoeducore.eduregistro.com`, puerto 443 y esquema https (la
   `REVERB_APP_SECRET` **no** va al frontend). Es la tarea F21 de la checklist.
5. **Probar de punta a punta**: un alumno con la página abierta y un docente activando un examen; el
   aviso debe llegar sin recargar. Si no llega, ver «Comprobar que funciona» en §3b.

Mientras esto no esté hecho no se rompe nada: el alumno ve el examen al recargar o en su notificación.
Seguimiento en la checklist, tarea **P9**.

---

## 11. Entorno local con Docker Compose (anotado el 11/10/2026)

El `Dockerfile` es el de producción; para trabajar en local hay un `docker-compose.yml` en la raíz que lo
reutiliza y arranca todo con un comando:

```
docker compose up --build
docker compose exec app php artisan migrate --force   # solo la primera vez
```

- **Servicios**: `app` (API en `localhost:8100`), `websocket` (Reverb en `localhost:8180`), `queue`
  (`queue:work --queue=default,correos`, necesario para correos, carga masiva y difusión) y `db` (Postgres 16 local, volumen `dbdata`).
- **No usa la Supabase del `.env`**: la base es local y vacía. Del `.env` solo lee `APP_KEY` (obligatoria) y,
  si existe, `OPENAI_API_KEY`.
- Correo en `log` (no envía nada) y Reverb encendido con claves de desarrollo (`local-dev-key`).
- Apagar: `docker compose down`; con `-v` también se borra la base.
- Estado: el archivo se validó con `docker compose config`, pero **no se probó levantándolo** (Docker estaba
  bloqueado en la máquina de desarrollo). Ojo: `migrate` a secas ejecuta la migración que borra
  `personal_access_tokens`; en una base local vacía no importa.
