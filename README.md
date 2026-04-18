# NeoEduCore — Backend API

Backend de la plataforma NeoEduCore, desarrollado con **Laravel 12** + **PostgreSQL**. Incluye autenticación con Laravel Sanctum y documentación de API con Swagger (L5-Swagger).

---

## Requisitos previos

- PHP >= 8.2 con extensiones: `pgsql`, `pdo_pgsql`, `mbstring`, `xml`, `curl`, `zip`, `gd`
- Composer >= 2
- PostgreSQL >= 14
- Node.js >= 18 (solo para assets)

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

# 8. Generar la documentación Swagger
php artisan l5-swagger:generate

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
| `L5_SWAGGER_GENERATE_ALWAYS` | Regenerar docs en cada request (solo dev) | `false` |

---

## Comandos útiles

```bash
# Tests
php artisan test

# Regenerar documentación Swagger
php artisan l5-swagger:generate

# Limpiar caché de configuración
php artisan config:clear && php artisan cache:clear
```

---

## Solución de problemas frecuentes

### `class "L5Swagger\L5SwaggerServiceProvider" not found`
La carpeta `vendor/` no está presente. Ejecutar:
```bash
composer install
```

### `No application encryption key has been specified`
Falta el `APP_KEY`. Ejecutar:
```bash
php artisan key:generate
```

### Error de conexión a PostgreSQL
Verificar que el servicio PostgreSQL esté corriendo y que las variables `DB_*` en `.env` sean correctas.

### `ext-gd is missing` al hacer `composer install`
Habilitar la extensión `gd` en el `php.ini` de XAMPP (o el sistema):
```
; Descomentar esta línea en php.ini:
extension=gd
```
Luego reiniciar el servidor PHP/Apache.
