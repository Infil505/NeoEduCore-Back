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
