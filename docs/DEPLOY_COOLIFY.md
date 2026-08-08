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
  php artisan queue:work --tries=3 --max-time=3600
  ```
- Mantiene el envío de correos (reset, alta masiva) en segundo plano. Coolify lo reinicia si cae.

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
CACHE_STORE=database          # o redis si agregás uno
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

## 7. Presupuesto de conexiones a Supabase
- El **session pooler (5432)** mantiene una conexión por worker. Con Octane, el contenedor usa pocas (worker threads comparten). Sumá: app + worker dedicado ≤ límite del plan Supabase.
- Si en producción escalás a varias instancias, vigilá ese total. El transaction pooler (6543) NO sirve aquí (rompe prepared statements con PDO — ver E9 en ESTADO_Y_PENDIENTES.md).

## 8. Notas
- `BCRYPT_ROUNDS=10` solo para el evento/piloto si esperás muchos logins a la vez; podés volver a 12 después.
- La imagen NO incluye `.env` (lo inyecta Coolify) ni `vendor` (se instala en el build).
- Primer admin: `php artisan db:seed` (una vez) o crearlo manualmente; luego el admin da de alta al resto vía `/register`.

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
