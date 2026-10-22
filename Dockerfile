# NeoEduCore — imagen de producción (Laravel + Octane + FrankenPHP)
# Pensada para DigitalOcean + Coolify. Un solo contenedor sirve HTTP con alta
# concurrencia (Octane mantiene la app en memoria → pocas conexiones a Supabase).
#
# Una sola imagen, TRES instancias independientes (cada una es un recurso en
# Coolify con su comando; ver DEPLOY_COOLIFY.md):
#   app        octane:start   API HTTP (CMD por defecto, puerto 8100)
#   websocket  reverb:start   WebSocket del aviso «examen activado» (puerto 8180)
#   worker     queue:work     correos, carga masiva y difusión de eventos

FROM dunglas/frankenphp:1-php8.4

# Extensiones PHP que usa el proyecto
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    gd \
    opcache \
    pcntl \
    redis

# curl y wget: el healthcheck de Coolify lo ejecuta DENTRO del contenedor y, según cómo esté
# configurado el recurso, usa una u otra (el 14/10 falló con «wget: not found»). Sin ellas el
# recurso queda «Unhealthy» aunque la app funcione y Coolify revierte el despliegue.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl wget \
    && rm -rf /var/lib/apt/lists/*

# Composer (binario oficial)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 1) Dependencias primero (mejor cache de capas). Incluye laravel/octane desde el lock.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# 2) Código de la app
COPY . .

# Carpetas que Laravel necesita escribir y que git no versiona (están vacías o con
# archivos ignorados): sin ellas `artisan` falla con «bootstrap/cache must be present and writable».
RUN mkdir -p bootstrap/cache storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && chmod -R ug+rwX bootstrap/cache storage

# 3) Autoload optimizado + opcache
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && cp -n .env.example .env || true

# Octane copia este script a public/ al arrancar si falta. Como `public/` es de root (solo lectura
# para la app: ahí no debe poder dejar un web shell quien consiga ejecutar código), se copia ya aquí.
RUN cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php

# Opcache afinado para Octane (app en memoria)
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.enable_cli=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=16"; \
    echo "opcache.max_accelerated_files=20000"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.jit=0"; \
    echo "opcache.jit_buffer_size=0"; \
  } > /usr/local/etc/php/conf.d/opcache.ini

ENV APP_ENV=production
ENV APP_DEBUG=false

# Usuario sin privilegios. Una ejecución remota de código (p. ej. PHP2Shell, CVE-2026-109618/9)
# da los permisos del proceso: como root controlaría todo el contenedor; como `app` solo puede
# escribir donde Laravel necesita (storage, bootstrap/cache) y el estado de Caddy. Los puertos
# (8100, 8180, 3000) son >1024, así que no hace falta ninguna capability. El código queda de root
# (solo lectura para la app): un atacante no puede reescribirlo.
RUN useradd --system --uid 10001 --user-group --no-create-home --shell /usr/sbin/nologin app     && mkdir -p /data/caddy /config/caddy     && chown -R app:app /data /config storage bootstrap/cache
ENV XDG_DATA_HOME=/data     XDG_CONFIG_HOME=/config

# Entrypoint común: cachea la configuración y ajusta Octane (ver docker/entrypoint.sh).
# `sed` quita los retornos de carro por si el archivo llegó con saltos de línea de Windows.
COPY docker/entrypoint.sh /usr/local/bin/neoeducore-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/neoeducore-entrypoint \
    && chmod +x /usr/local/bin/neoeducore-entrypoint

# 8100 = API (Octane; no usa 8000 porque es el del panel de Coolify). El puerto se
# fija en docker/entrypoint.sh y se cambia con OCTANE_PORT.
# 8180 = WebSocket (Reverb): lo escucha solo la instancia `websocket`.
EXPOSE 8100 8180

USER app

ENTRYPOINT ["neoeducore-entrypoint"]

# Servidor HTTP con Octane sobre FrankenPHP.
# (Las migraciones se corren como paso de despliegue en Coolify, no aquí.)
# `--max-requests=500`: Octane mantiene la app EN MEMORIA entre peticiones, que es
# de donde sale su rendimiento, pero también significa que cualquier fuga de
# memoria o estado residual de un worker se acumula indefinidamente. Reciclarlo
# cada 500 peticiones acota ese arrastre a cambio de un arranque en frío
# ocasional. Es además la contramedida barata frente a una petición maliciosa que
# deje un worker en mal estado: se recicla solo.
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--max-requests=500"]
