#!/bin/sh
# NeoEduCore — entrypoint del contenedor.
#
# La misma imagen se despliega como TRES instancias independientes; lo que cambia
# es el comando (ver DEPLOY_COOLIFY.md §2, §3 y §3b):
#
#   app        php artisan octane:start ...     API HTTP (CMD por defecto)
#   websocket  php artisan reverb:start ...     WebSocket (aviso «examen activado»)
#   worker     php artisan queue:work ...       correos, carga masiva, difusión
#
# El rol se puede fijar con CONTAINER_ROLE=websocket|worker|app en vez de cambiar el comando.
#
# Este script solo prepara lo común (caché de configuración) y, en la API, el
# puerto y los workers de Octane. Ya no levanta Reverb: es su propia instancia, de
# modo que si cae no se lleva la API y puede reiniciarse o escalarse por separado.

set -u

# Rol por variable de entorno, para paneles (Coolify) que no dejan cambiar el comando.
# Si CONTAINER_ROLE está definida, sustituye al comando de la imagen:
#   websocket → reverb:start    worker → queue:work    app (o vacía) → el CMD por defecto
case "${CONTAINER_ROLE:-}" in
    websocket)
        set -- php artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8180}" --no-interaction
        ;;
    worker)
        set -- php artisan queue:work --queue=default,correos --tries=3 --max-time=3600 --max-jobs=500 --memory=128
        ;;
    app|"") ;;
    *)
        echo "[entrypoint] CONTAINER_ROLE desconocido: ${CONTAINER_ROLE} (app, websocket, worker)" >&2
        exit 1
        ;;
esac

servidor_http() {
    [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ] && [ "${3:-}" = "octane:start" ]
}

# Cachea config, rutas, eventos y vistas: menos memoria y arranque más rápido.
# Va aquí y no en el build porque necesita las variables reales de cada instancia.
# Si falla, arranca igual sin caché.
php artisan optimize --no-interaction >&2 \
    || echo "[entrypoint] optimize falló; se arranca sin caché" >&2

if servidor_http "$@"; then
    # Puerto de la API: 8100 por defecto (el 8000 lo usa el panel de Coolify).
    set -- "$@" --port="${OCTANE_PORT:-8100}"

    # Un worker de Octane retiene una conexión a la BD: el tope lo marca el
    # presupuesto de conexiones, no la RAM. Sin OCTANE_WORKERS Octane usa uno por núcleo.
    if [ -n "${OCTANE_WORKERS:-}" ]; then
        set -- "$@" --workers="$OCTANE_WORKERS"
    fi
fi

# exec: el comando pasa a ser PID 1 y recibe las señales de parada.
exec "$@"
