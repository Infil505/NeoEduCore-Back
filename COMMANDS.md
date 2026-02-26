# Comandos para NeoEduCore API

Guía completa de comandos para trabajar con el proyecto backend.

## 🚀 Instalación Inicial

### 1. Clonar y Configurar
```bash
# Clonar repositorio
git clone <repo-url>
cd NeoEduCore

# Instalar dependencias PHP (Composer)
composer install

# Instalar dependencias Node (si tienes package.json)
npm install
```

### 2. Configuración de Entorno
```bash
# Copiar archivo de configuración
cp .env.example .env

# Generar APP_KEY
php artisan key:generate

# Generar JWT_SECRET (si usas JWT)
php artisan jwt:secret
```

### 3. Base de Datos
```bash
# Crear base de datos (PostgreSQL)
# En pgAdmin o comandos PostgreSQL:
CREATE DATABASE neoeducore;

# Configurar en .env:
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=neoeducore
# DB_USERNAME=postgres
# DB_PASSWORD=tu_contraseña

# Ejecutar migraciones
php artisan migrate

# Ejecutar migraciones con seeders
php artisan migrate --seed

# Solo seeders
php artisan db:seed

# Específico seeder
php artisan db:seed --class=CoreTablesSeeder
```

---

## 🧪 Tests

### Ejecutar Tests
```bash
# Todos los tests
php artisan test

# Tests específicos
php artisan test tests/Feature/Auth/
php artisan test tests/Feature/Crud/StudentsCrudTest.php
php artisan test tests/Feature/Crud/StudentsCrudTest.php::test_list_students

# Tests con salida detallada
php artisan test --verbose

# Tests con coverage (cobertura de código)
php artisan test --coverage

# Tests en paralelo (más rápido)
php artisan test --parallel

# Tests sin parar en primer error
php artisan test --bail=false
```

### Limpiar Tests
```bash
# Limpiar base de datos de test
php artisan migrate:fresh --env=testing

# Recrear base de datos de test con seeders
php artisan migrate:fresh --seed --env=testing
```

---

## 🗄️ Migraciones y Base de Datos

### Migraciones
```bash
# Ver estado de migraciones
php artisan migrate:status

# Ejecutar todas las migraciones
php artisan migrate

# Ejecutar migraciones pasadas
php artisan migrate:refresh

# Ejecutar migraciones y seeders
php artisan migrate:fresh --seed

# Hacer rollback (deshacer última migración)
php artisan migrate:rollback

# Hacer rollback completo
php artisan migrate:reset

# Hacer rollback de pasos específicos
php artisan migrate:rollback --step=3

# Crear nueva migración
php artisan make:migration create_tabla_table

# Crear migración con scaffolding
php artisan make:migration create_users_table --create=users
```

### Seeders
```bash
# Ejecutar todos los seeders
php artisan db:seed

# Ejecutar seeder específico
php artisan db:seed --class=CoreTablesSeeder

# Ejecutar migraciones y seeders
php artisan migrate --seed

# Resetear BD y ejecutar seeders
php artisan migrate:fresh --seed

# Hacer rollback de una seed (no existe, pero puedes usar migrate:refresh)
php artisan migrate:refresh --seed

# Crear nuevo seeder
php artisan make:seeder UserSeeder
```

---

## 🏃 Servidor de Desarrollo

### Iniciar Servidor
```bash
# Servidor de desarrollo (localhost:8000)
php artisan serve

# Servidor en puerto específico
php artisan serve --port=8001

# Servidor en host específico
php artisan serve --host=0.0.0.0 --port=8000

# Con auto-reload (requiere instalación adicional)
php artisan serve --host=127.0.0.1 --port=8000 --watch
```

### Compilar Assets (Vite)
```bash
# Compilar para desarrollo
npm run dev

# Compilar para producción
npm run build

# Watch mode (recompila automáticamente)
npm run watch
```

---

## 🏗️ Generadores de Código

### Modelos
```bash
# Crear modelo
php artisan make:model UserProfile

# Modelo con migración
php artisan make:model Post -m

# Modelo con migración, controller, factory
php artisan make:model Post -mcf

# Modelo con todos (migration, controller, factory, seeder, resource)
php artisan make:model Post -mcs --resource
```

### Controllers
```bash
# Crear controller
php artisan make:controller StudentController

# Controller con métodos CRUD
php artisan make:controller PostController --resource

# Controller invocable (single action)
php artisan make:controller DownloadController --invokable

# API Controller sin create/edit
php artisan make:controller Api/PostController --api
```

### Factories
```bash
# Crear factory
php artisan make:factory PostFactory

# Factory para modelo específico
php artisan make:factory StudentFactory --model=Student
```

### Seeders
```bash
# Crear seeder
php artisan make:seeder AdminSeeder
```

### Requests (Form Validation)
```bash
# Crear request class
php artisan make:request StorePostRequest

# Request para modelo específico
php artisan make:request StoreStudentRequest
```

### Migrations
```bash
# Crear migración vacía
php artisan make:migration add_phone_to_users_table

# Migración de tabla nueva
php artisan make:migration create_posts_table --create=posts

# Migración de tabla nueva con scaffolding
php artisan make:migration add_column_to_table --table=users
```

---

## 📝 Artisan Utilities

### Cache
```bash
# Limpiar todo el cache
php artisan cache:clear

# Limpiar cache de configuración
php artisan config:cache

# Limpiar cache de rutas
php artisan route:cache

# Limpiar cache de vistas
php artisan view:clear

# Limpiar todo
php artisan cache:clear && php artisan config:clear && php artisan route:clear && php artisan view:clear
```

### Rutas
```bash
# Listar todas las rutas
php artisan route:list

# Listar rutas con más detalles
php artisan route:list -v

# Listar rutas específicas (api)
php artisan route:list --path=api

# Filtrar por método
php artisan route:list --method=POST
```

### Configuración
```bash
# Publicar configuración de paquetes
php artisan vendor:publish

# Publicar solo migraciones de un paquete
php artisan vendor:publish --tag=migrations

# Publicar configuración de Sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### Storage
```bash
# Crear link simbólico storage -> public
php artisan storage:link

# Crear link específico
php artisan storage:link --relative
```

---

## 🔧 Development & Debugging

### Tinker (REPL)
```bash
# Abrir consola interactiva
php artisan tinker

# Dentro de tinker (ejemplos):
>>> $user = User::first();
>>> $user->email;
>>> User::count();
>>> DB::table('users')->count();
```

### Logs
```bash
# Ver logs en tiempo real
tail -f storage/logs/laravel.log

# En Windows PowerShell
Get-Content storage/logs/laravel.log -Wait -Tail 50

# En Windows CMD
type storage/logs/laravel.log
```

### Database Queries
```bash
# Ver todas las querys ejecutadas (en tinker)
>>> \DB::enableQueryLog();
>>> User::all();
>>> \DB::getQueryLog();
```

---

## 🚨 Error y Debug

### Debug Mode
```bash
# Activar debug en .env
APP_DEBUG=true

# Desactivar en producción
APP_DEBUG=false
```

### Validación
```bash
# Limpiar config de validaciones
php artisan config:clear

# Publicar archivos de validación personalizados
php artisan vendor:publish --tag=laravel-validation
```

---

## 📦 Composer Útil

```bash
# Instalar dependencias
composer install

# Actualizar dependencias
composer update

# Instalar paquete específico
composer require package/name

# Instalar paquete de desarrollo
composer require --dev package/name

# Remover paquete
composer remove package/name

# Autoload dump (regenerar autoloader)
composer dump-autoload

# Autoload optimizado para producción
composer dump-autoload --optimize

# Ver dependencias instaladas
composer show

# Ver dependencias outdated
composer outdated
```

---

## 🔐 Seguridad

### API Token Management (Sanctum)
```bash
# En tinker:
>>> $token = $user->createToken('token-name');
>>> $token->plainTextToken;
>>> $token->accessToken;

# Revocar token
>>> $user->tokens()->delete();
>>> $user->tokens()->where('name', 'token-name')->delete();
```

### Password Management
```bash
# En tinker:
>>> use Illuminate\Support\Facades\Hash;
>>> $user = User::first();
>>> $user->update(['password_hash' => Hash::make('newpassword')]);
```

---

## 🚀 Deployment

### Producción
```bash
# Compilar assets
npm run build

# Optimizar autoloader
composer install --optimize-autoloader --no-dev

# Cache configuración
php artisan config:cache

# Cache rutas
php artisan route:cache

# Cache vistas
php artisan view:cache

# Ejecutar migraciones en producción
php artisan migrate --force

# Generar app key si falta
php artisan key:generate

# Limpiar todo antes de deploy
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

## 📊 Monitoreo

### Queue (si usas colas)
```bash
# Procesar colas
php artisan queue:work

# Procesar con timeout específico
php artisan queue:work --timeout=60

# Procesar una conexión específica
php artisan queue:work redis

# Ver estado de colas
php artisan queue:failed
```

### Scheduled Tasks
```bash
# Ejecutar tasks programadas
php artisan schedule:run

# Listar tasks programadas
php artisan schedule:list

# En crontab (ejecutar cada minuto):
* * * * * cd /path/to/artisan && php artisan schedule:run >> /dev/null 2>&1
```

---

## 🧹 Limpieza y Mantenimiento

### Limpiar Archivos Temporales
```bash
# Limpiar bootstrap cache
php artisan cache:clear

# Limpiar config cache
php artisan config:clear

# Limpiar route cache
php artisan route:clear

# Limpiar view cache
php artisan view:clear

# Limpiar todo
php artisan optimize:clear
```

### Storage
```bash
# Borrar archivos antiguos en storage
php artisan storage:purge

# Limpiar uploads temporales
rm -rf storage/app/uploads/*
```

---

## 👥 Usuarios y Permisos (Si usas Laravel Nova o Spatie)

```bash
# Crear admin user
php artisan tinker
>>> \App\Models\Admin\User::factory()->admin()->create(['email' => 'admin@mail.com']);

# Crear teacher user
>>> \App\Models\Admin\User::factory()->teacher()->create(['email' => 'teacher@mail.com']);

# Crear student user
>>> \App\Models\Admin\User::factory()->student()->create(['email' => 'student@mail.com']);
```

---

## 🔄 Workflows Completos

### Setup Inicial Completo
```bash
composer install
cp .env.example .env
php artisan key:generate
# Configurar .env (DB, etc.)
php artisan migrate --seed
npm install
npm run dev
php artisan serve
```

### Desarrollo Diario
```bash
# Terminal 1: Servidor Laravel
php artisan serve

# Terminal 2: Assets Vite
npm run dev

# Terminal 3: Tests (opcional)
php artisan test --watch
```

### Antes de Commit
```bash
# Ejecutar tests
php artisan test

# Limpiar código (si tienes Pint)
./vendor/bin/pint

# Format código (si tienes prettier)
npm run format
```

### Antes de Producción
```bash
# Tests
php artisan test

# Limpiar cache
php artisan optimize:clear

# Compilar assets
npm run build

# Migraciones
php artisan migrate --force

# Cache configuración
php artisan config:cache
php artisan route:cache
```

---

## 📖 Ayuda y Documentación

```bash
# Ver ayuda de comando
php artisan help migrate

# Ver lista de todos los comandos
php artisan list

# Ver comandos específicos
php artisan list database
php artisan list cache

# Documentación de Laravel
# https://laravel.com/docs

# API Documentation
# Accede a: http://localhost:8000/docs/
```

---

## 🐛 Troubleshooting

### Problemas Comunes
```bash
# Class not found
composer dump-autoload

# Permission denied
chmod -R 755 storage bootstrap/cache

# Port already in use
php artisan serve --port=8001

# Database connection error
# Verifica .env: DB_CONNECTION, DB_HOST, DB_USERNAME, DB_PASSWORD

# Memory exhausted
# Aumenta PHP memory: php -d memory_limit=512M artisan migrate

# Token generation failed en tests
# Asegúrate que APP_KEY esté seteado en .env.testing
```

---

## ✨ Tips y Atajos

```bash
# Crear todo de una vez (Model + Migration + Factory + Seeder + Controller)
php artisan make:model Post -mfsc --resource

# Resetear BD completamente
php artisan migrate:refresh --seed

# Ejecutar comando en tinker sin interactividad
php artisan tinker --no-history

# Ver info de la aplicación
php artisan about

# Actualizar dependencias de seguridad
composer update --with-all-dependencies

# Verificar compatibilidad de PHP
php -v
php -m (ver extensiones)
```

---

**Última actualización**: 25 de Febrero de 2026
**Laravel Version**: 12
**PHP Version**: 8.0
