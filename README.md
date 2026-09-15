# NeoEduCore — Backend API

Backend de la plataforma NeoEduCore, desarrollado con **Laravel 12** + **PostgreSQL**. API REST multi-tenant, autenticación con **Laravel Sanctum** (tokens opacos, no JWT) y documentación con Swagger (L5-Swagger). En producción corre sobre **Laravel Octane + FrankenPHP**.

El frontend es un proyecto aparte (React + Vite + TypeScript): este repositorio **no sirve HTML**, solo JSON — salvo las plantillas de correo en `resources/views/emails/`.

| Documento | Para qué |
|---|---|
| `docs/ESTADO_Y_PENDIENTES.md` | Estado por módulo, brechas, TODO priorizado y referencia de endpoints |
| `docs/ANALISIS_MODELO_DATOS_TFG.md` | Modelo de datos y qué corregir del informe del TFG |
| `docs/ANALISIS_CONCURRENCIA.md` | Modelo de capacidad y prueba de carga |
| `docs/DEPLOY_COOLIFY.md` | Despliegue en DigitalOcean + Coolify |
| `tests/README.md` | Qué cubre cada test |
| `postman/README.md` | Colección de Postman y su generador |

---

## Requisitos previos

- PHP >= 8.2 con extensiones: `pgsql`, `pdo_pgsql`, `mbstring`, `xml`, `curl`, `zip`, `gd`
- Composer >= 2
- PostgreSQL >= 14
- Node.js >= 18 — **opcional**, solo para validar los diagramas Mermaid (`npm run validar-diagramas`). El backend no compila assets: no hay Vite ni CSS/JS propios

---

## Instalación en una máquina nueva

```bash
# 1. Clonar el repositorio
git clone <url-del-repo>
cd NeoEduCore

# 2. Instalar dependencias PHP
composer install

# 3. Copiar el archivo de entorno y configurarlo
cp .env.example .env

# 4. Generar la clave de la aplicación
php artisan key:generate

# 5. Crear la base de datos en PostgreSQL y ajustar .env:
#    DB_DATABASE, DB_USERNAME, DB_PASSWORD

# 6. Ejecutar migraciones y seeders
php artisan migrate --seed

# 7. Publicar assets de Swagger
php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider"

# 8. Generar la documentación OpenAPI (desde las rutas reales)
php artisan openapi:generate

# 9. Levantar el servidor
php artisan serve
```

La documentación Swagger estará disponible en: `http://localhost:8000/api/documentation`

---

## Variables de entorno relevantes

| Variable | Descripción | Ejemplo |
|---|---|---|
| `APP_KEY` | Clave de cifrado (generada con `key:generate`) | `base64:...` |
| `DB_CONNECTION` | Motor de base de datos | `pgsql` |
| `DB_HOST` | Host de PostgreSQL | `127.0.0.1` |
| `DB_PORT` | Puerto | `5432` |
| `DB_DATABASE` | Nombre de la base de datos | `neoeducore` |
| `DB_USERNAME` | Usuario | `postgres` |
| `DB_PASSWORD` | Contraseña | — |
| `OPENAI_API_KEY` | Clave de API de OpenAI (tutor IA y recomendaciones) | `sk-...` |
| `OPENAI_REQUEST_TIMEOUT` | Timeout en segundos para llamadas a OpenAI | `15` |
| `L5_SWAGGER_GENERATE_ALWAYS` | Regenerar docs en cada request (solo dev) | `false` |
| `CACHE_STORE` | **Nunca `array` fuera de tests**: los rate limiters viven en el caché y con Octane cada worker llevaría su propio contador | `file` |
| `PG_DUMP_PATH` | Ruta a `pg_dump`, necesaria para `schema:dump-sql` | `C:\|/usr/bin/pg_dump` |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | Caducidad del token de API | `720` |

---

## Tests

La suite corre contra **PostgreSQL real**, no SQLite: se usan `jsonb`, `COUNT(*) FILTER` y `PERCENTILE_CONT`, que SQLite no tiene.

```bash
php artisan test                                  # toda la suite
php artisan test tests/Feature/Crud/ReportsTest.php
php artisan test --filter=QueryBudget             # presupuesto de queries por endpoint
php artisan test --coverage --min=70              # requiere Xdebug o PCOV
```

Los tests **no ejecutan las migraciones**: cargan `database/sql/01_schema.sql` (ver el trait `UsesPostgresSchema`). La base la fija `phpunit.xml` (`DB_DATABASE=neoeducore`).

---

## Cambiar el esquema de la base de datos

`database/sql/01_schema.sql` es un **artefacto generado**, no se edita a mano. El orden importa: si se omite el tercer paso, los tests siguen en verde mientras producción falla.

```bash
php artisan make:migration descripcion_del_cambio
php artisan migrate
php artisan schema:dump-sql        # regenera 01_schema.sql (necesita PG_DUMP_PATH)
# commit de la migración Y del schema, siempre juntos
```

---

## Comandos útiles

```bash
# Servidor de desarrollo
php artisan serve
composer run dev                    # servidor + worker de cola + logs

# Cola (correos de reset y alta masiva)
php artisan queue:work

# Documentación
php artisan openapi:generate        # OpenAPI (117 endpoints) → /api/documentation

# Crear el operador de la plataforma (no hay ruta de API que lo cree)
php artisan superadmin:create --email=ops@ejemplo.com --name="Operaciones"

# Sin contraseña: la cuenta nace inactiva y su dueño la define desde el enlace.
# El enlace se arma con APP_URL, así que el envío se detiene si apunta a localhost.
php artisan superadmin:create --send-setup-link --email=ops@ejemplo.com --name="Operaciones"
php postman/generate_postman_collection.php   # colección de Postman desde route:list

# Inspección
php artisan route:list --path=reports
php artisan test --filter=QueryBudget

# Limpiar cachés
php artisan config:clear && php artisan cache:clear && php artisan route:clear
```

> `bootstrap/cache/packages.php` y `services.php` **no** están versionados (`.gitignore` excluye
> `/bootstrap/cache/*.php`). Laravel los regenera solo al instalar dependencias, así que no
> aparecen en `git status` ni llegan en un `git pull`: no hay que hacer nada con ellos.
> No confundir con `config/services.php`, que sí está versionado.

---

## Solución de problemas frecuentes

### `class "L5Swagger\L5SwaggerServiceProvider" not found`
La carpeta `vendor/` no está presente. Ejecutar:
```bash
composer install
```

### `composer install` falla
Antes de nada, leer el error: casi siempre indica una extensión de PHP que falta (ver `ext-gd` más
abajo) o una versión de PHP por debajo de la que pide el proyecto (`^8.2`). Comprobar con:
```bash
php -v
composer check-platform-reqs
```
Instalar la extensión que falte o actualizar PHP resuelve el problema sin tocar las dependencias, y
es siempre la primera opción.

Si aun así no arranca, como último recurso:
```bash
composer update
```

> ⚠️ `composer update` **no** es equivalente a `composer install`. Ignora el `composer.lock` y
> resuelve versiones nuevas de todos los paquetes, con lo que se acaba trabajando sobre un conjunto
> de dependencias distinto al que está probado y desplegado. Puede introducir fallos que no se ven
> hasta ejecutar los tests o hasta producción. Si se usa:
>
> - ejecutar después `php artisan test` y confirmar que la suite sigue en verde;
> - **no** commitear el `composer.lock` modificado salvo que la actualización sea intencionada y
>   acordada — si se sube por accidente, la cambia para todo el equipo y para el despliegue.
>
> Para revertir el lock: `git checkout composer.lock && composer install`.

### `No application encryption key has been specified`
Falta el `APP_KEY`. Ejecutar:
```bash
php artisan key:generate
```

### Error de conexión a PostgreSQL
Verificar que el servicio PostgreSQL esté corriendo y que las variables `DB_*` en `.env` sean correctas.

### `ext-gd is missing` al hacer `composer install`
Primero, localizar el `php.ini` activo:
```bash
php --ini
```
Abrir ese archivo y descomentar (quitar el `;`):
```
extension=gd
```
En Windows con PHP descargado directamente, verificar además que el archivo `php_gd.dll` existe en la carpeta `ext/` de la instalación de PHP. Si no está, descargar el paquete **Thread Safe** de la misma versión desde php.net — los paquetes NTS no incluyen todas las extensiones.

Tras editar el `php.ini`, reiniciar la terminal y verificar con:
```bash
php -m | grep gd
```
