#!/bin/sh
# NeoEduCore — entrypoint del contenedor.
#
# Arranca Laravel Reverb (el WebSocket del aviso «examen activado») DENTRO del
# mismo contenedor que sirve la API, para no tener que mantener un recurso más
# en Coolify. Si Reverb se cae, se vuelve a levantar solo.
#
# Solo cuando el comando es el servidor HTTP (`octane:start`). La misma imagen se
# usa para el worker de cola y el scheduler con otro comando, y esos no deben
# abrir un segundo WebSocket.
#
# Se apaga con REVERB_EMBEDDED=false (p. ej. si algún día Reverb pasa a un
# recurso propio o a varios servidores con Redis).
#
# Si Reverb no puede arrancar NO se tumba la API: el aviso en vivo se pierde y el
# alumno ve el examen al recargar o en su notificación.

set -u

servidor_http() {
    [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ] && [ "${3:-}" = "octane:start" ]
}

reverb_activo() {
    [ "${REVERB_EMBEDDED:-true}" = "true" ] \
        && [ "${BROADCAST_CONNECTION:-}" = "reverb" ] \
        && [ -n "${REVERB_APP_KEY:-}" ] \
        && [ -n "${REVERB_APP_SECRET:-}" ]
}

# Bucle de reinicio: espera creciente (2 s → 30 s) para no girar en vacío si
# Reverb falla nada más arrancar, y vuelve a 2 s si vivió un buen rato.
vigilar_reverb() {
    espera=2
    while true; do
        inicio=$(date +%s)
        php artisan reverb:start \
            --host="${REVERB_SERVER_HOST:-0.0.0.0}" \
            --port="${REVERB_SERVER_PORT:-8080}" \
            --no-interaction
        vivio=$(( $(date +%s) - inicio ))

        # Si vivió un buen rato el fallo fue puntual: se empieza de nuevo por 2 s.
        [ "$vivio" -ge 60 ] && espera=2

        echo "[entrypoint] Reverb terminó tras ${vivio}s; reinicio en ${espera}s" >&2
        sleep "$espera"

        [ "$espera" -lt 30 ] && espera=$(( espera * 2 ))
    done
}

if servidor_http "$@"; then
    if reverb_activo; then
        echo "[entrypoint] Arrancando Reverb en ${REVERB_SERVER_HOST:-0.0.0.0}:${REVERB_SERVER_PORT:-8080}" >&2
        vigilar_reverb &
    else
        echo "[entrypoint] Reverb no se arranca (REVERB_EMBEDDED, BROADCAST_CONNECTION y REVERB_APP_KEY/SECRET)" >&2
    fi
fi

# exec: el comando pasa a ser PID 1 y recibe las señales de parada.
exec "$@"
