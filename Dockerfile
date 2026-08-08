# NeoEduCore — imagen de producción (Laravel + Octane + FrankenPHP)
# Pensada para DigitalOcean + Coolify. Un solo contenedor sirve HTTP con alta
# concurrencia (Octane mantiene la app en memoria → pocas conexiones a Supabase).
#
# El worker de cola y el scheduler se despliegan como recursos APARTE en Coolify
# (misma imagen, otros comandos) — ver DEPLOY_COOLIFY.md.

FROM dunglas/frankenphp:1-php8.3

# Extensiones PHP que usa el proyecto
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    gd \
    opcache \
    pcntl

# Composer (binario oficial)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 1) Dependencias primero (mejor cache de capas). Incluye laravel/octane desde el lock.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# 2) Código de la app
COPY . .

# 3) Autoload optimizado + opcache
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && cp -n .env.example .env || true

# Opcache afinado para Octane (app en memoria)
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.enable_cli=1"; \
    echo "opcache.memory_consumption=256"; \
    echo "opcache.max_accelerated_files=20000"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.jit=1255"; \
    echo "opcache.jit_buffer_size=128M"; \
  } > /usr/local/etc/php/conf.d/opcache.ini

ENV APP_ENV=production
ENV APP_DEBUG=false

EXPOSE 8000

# Servidor HTTP con Octane sobre FrankenPHP.
# (Las migraciones se corren como paso de despliegue en Coolify, no aquí.)
# `--max-requests=500`: Octane mantiene la app EN MEMORIA entre peticiones, que es
# de donde sale su rendimiento, pero también significa que cualquier fuga de
# memoria o estado residual de un worker se acumula indefinidamente. Reciclarlo
# cada 500 peticiones acota ese arrastre a cambio de un arranque en frío
# ocasional. Es además la contramedida barata frente a una petición maliciosa que
# deje un worker en mal estado: se recicla solo.
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--max-requests=500"]
