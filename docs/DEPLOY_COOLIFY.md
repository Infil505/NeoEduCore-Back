# Despliegue en DigitalOcean + Coolify

Guía para desplegar NeoEduCore (Laravel + Octane + FrankenPHP) y aguantar picos de ~200 usuarios concurrentes, usando solo recursos gratuitos/incluidos.

---

## 1. Droplet
- Crear el droplet **en la misma región que Supabase** (`us-west-2` → en DO eso es **SFO3 / San Francisco**). Esto baja la latencia por query de ~100 ms a ~1-2 ms. Es la mejora más grande.
- Instalar Coolify en el droplet (script oficial).

## 2. Recurso "App" (servidor HTTP)
- Tipo: **Dockerfile** (Coolify lo detecta; usa el `Dockerfile` del repo).
- Puerto interno: **8000**.
- Octane sobre FrankenPHP mantiene la app en memoria → alta concurrencia con pocas conexiones a BD.

## 3. Recurso "Worker" (cola de correos)
- Otro recurso en Coolify con **la misma imagen**, pero comando:
  ```
  php artisan queue:work --queue=default,correos --tries=3 --max-time=3600
  ```
- Mantiene el envío de correos (reset, alta masiva) en segundo plano. Coolify lo reinicia si cae.

## 3b. WebSocket (Reverb) — va DENTRO del recurso App, no es un recurso aparte
El aviso «examen activado» sin recargar usa Laravel Reverb. **No hace falta crear otro recurso en
Coolify**: el `Dockerfile` arranca Reverb junto al servidor HTTP (`docker/entrypoint.sh`) y lo
reinicia solo si cae (espera 2 s → 4 → 8… hasta 30 s; vuelve a 2 s si vivió más de un minuto).

- **Cuándo arranca:** solo en el contenedor del servidor HTTP (`octane:start`) y solo si
  `BROADCAST_CONNECTION=reverb` y están `REVERB_APP_KEY` y `REVERB_APP_SECRET`. El worker de cola y
  el scheduler usan la misma imagen con otro comando y **no** abren un segundo WebSocket.
- **Apagarlo:** `REVERB_EMBEDDED=false` (p. ej. si algún día pasa a un recurso propio).
- **Si Reverb no puede arrancar no se cae la API.** El aviso en vivo se pierde y el alumno ve el
  examen al recargar o en su notificación. El log del contenedor dice qué pasó (`[entrypoint] …`).

### Exponerlo (lo único que se configura en Coolify)
El navegador se conecta **directo** al WebSocket, y desde una página `https` solo admite `wss://`.
En el recurso App, en **Domains**, añadir un segundo dominio apuntando al puerto 8080:

```
https://api.tu-dominio.com:8000,https://ws.tu-dominio.com:8080
```

Coolify (Traefik) pone el TLS y los certificados; el contenedor escucha en claro en 8080.

### Variables (en Coolify, no en el repo)
```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=neoeducore
REVERB_APP_KEY=<cadena larga y aleatoria>      # el frontend la usa para conectarse
REVERB_APP_SECRET=<cadena larga y aleatoria>   # SOLO en el servidor, nunca en el frontend
REVERB_HOST=ws.tu-dominio.com                  # dominio público del WebSocket
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0                     # dónde escucha el proceso (dentro del contenedor)
REVERB_SERVER_PORT=8080
FRONTEND_URL=https://<dominio del frontend>    # Reverb solo acepta conexiones desde este host
```
Generar las dos claves: `php -r "echo bin2hex(random_bytes(24));"`. **El worker de cola necesita
las mismas variables** (es quien publica el evento hacia Reverb), así que van en los dos recursos.

### Comprobar que funciona
1. En el log de la App, al arrancar: `[entrypoint] Arrancando Reverb en 0.0.0.0:8080`.
2. Activar un examen con una cuenta de docente y ver `examen.activado` llegar a un alumno con la
   página abierta, sin recargar.
3. Si no llega: ¿el worker corre (`queue:work`)? ¿`REVERB_HOST` es el dominio público y no
   `localhost`? ¿`FRONTEND_URL` coincide con el origen del frontend?

### Límites
- **Una sola réplica de la App.** Con varias, cada una tendría su propio Reverb y un alumno
  conectado a una no recibiría lo publicado en otra. Para escalar hay que activar el escalado de
  Reverb con Redis (`REVERB_SCALING_ENABLED=true`) o sacarlo a un recurso propio.
- Comparte CPU y memoria con Octane. Para el piloto (~200 alumnos) sobra; si se nota, es el primer
  candidato a separarse.

## 4. Scheduled task (scheduler de Laravel)
- En Coolify → Scheduled Tasks, cada minuto:
  ```
  php artisan schedule:run
  ```
- Si NO querés el worker dedicado del paso 3, el scheduler ya drena la cola cada minuto (definido en `routes/console.php`). Con worker dedicado, este task queda para futuras tareas programadas.

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

# Correo (Gmail u otro SMTP)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=...

OPENAI_API_KEY=...
OPENAI_REQUEST_TIMEOUT=15     # sin esto rige el default de 30 s y un worker
                              # se queda bloqueado el doble de tiempo

# Para el pico de logins simultáneos: bcrypt más barato (sigue seguro)
BCRYPT_ROUNDS=10
```

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


## 10. Pendiente en Coolify: poner en marcha el WebSocket (anotado el 08/10/2026)

El código ya está hecho. **No hace falta copiar el proyecto ni crear otro recurso**: es el mismo
repositorio y la misma imagen, y Reverb arranca dentro del recurso App (§3b). Lo que falta es solo
configuración, en este orden:

1. **Redesplegar la App** para que construya la imagen con `docker/entrypoint.sh` (es lo que arranca
   Reverb). Mirar el log al arrancar: debe aparecer `[entrypoint] Arrancando Reverb en 0.0.0.0:8080`.
   Si aparece `Reverb no se arranca…`, falta alguna variable del paso 3.
2. **Segundo dominio en el recurso App**, en Domains, hacia el puerto 8080:
   `https://api.tu-dominio.com:8000,https://ws.tu-dominio.com:8080`. Crear antes el registro DNS de
   `ws.tu-dominio.com` hacia el mismo droplet. Coolify pone el TLS.
3. **Variables en la App Y en el recurso Worker** (el worker es quien publica el evento):
   `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID=neoeducore`, `REVERB_APP_KEY` y `REVERB_APP_SECRET`
   (largas y aleatorias: `php -r "echo bin2hex(random_bytes(24));"`), `REVERB_HOST=ws.tu-dominio.com`,
   `REVERB_PORT=443`, `REVERB_SCHEME=https`, `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8080` y
   `FRONTEND_URL` con el dominio real del frontend. Reiniciar App y Worker después.
4. **Pasarle al frontend** `REVERB_APP_KEY`, `ws.tu-dominio.com`, puerto 443 y esquema https (la
   `REVERB_APP_SECRET` **no** va al frontend). Es la tarea F21 de la checklist.
5. **Probar de punta a punta**: un alumno con la página abierta y un docente activando un examen; el
   aviso debe llegar sin recargar. Si no llega, ver «Comprobar que funciona» en §3b.

Mientras esto no esté hecho no se rompe nada: el alumno ve el examen al recargar o en su notificación.
Seguimiento en la checklist, tarea **P9**.
