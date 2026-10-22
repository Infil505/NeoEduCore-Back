<?php

/*
 * Respuesta mínima para el healthcheck de Coolify en el Worker.
 *
 * El Worker (`queue:work` + scheduler) no es un servidor HTTP, pero Coolify comprueba TODOS los recursos
 * con una petición HTTP y, si no contestan, revierte el despliegue al contenedor anterior (pasó tres veces
 * el 14/10/2026). El entrypoint arranca esto con `php -S` en `HEALTH_PORT` (3000 por defecto, que es el
 * puerto que Coolify prueba si el recurso no tiene puertos expuestos).
 *
 * Solo dice «el contenedor está vivo»: no comprueba la cola. Lo normal es desactivar el healthcheck del
 * Worker en Coolify; esto es la red de seguridad para que un despliegue no se pierda si se queda activo.
 */
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
