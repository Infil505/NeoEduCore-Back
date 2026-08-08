<?php

/*
|--------------------------------------------------------------------------
| Proxies de confianza
|--------------------------------------------------------------------------
|
| Sin esto, `$request->ip()` devuelve la IP del **proxy**, no la del cliente, y
| todos los límites por IP se vuelven inútiles: en producción la aplicación está
| detrás de Traefik (lo pone Coolify), así que sin configurarlo el sistema entero
| comparte un único cubo de rate limiting. El test de «un aula tras el mismo NAT»
| pasaría a ser «internet entero tras la misma IP», y el servicio se
| autobloquearía sin que nadie lo atacara.
|
| Se aplica en `AppServiceProvider::configurarProxiesDeConfianza()` con
| `TrustProxies::at()`. **No puede ir en `bootstrap/app.php`**: allí las
| variables de entorno todavía no están cargadas y `env()` devuelve null sin
| avisar.
|
| ---------------------------------------------------------------------------
| Por qué el valor por defecto son rangos privados
| ---------------------------------------------------------------------------
|
| Solo se confía en la cabecera `X-Forwarded-For` cuando quien conecta viene de
| una IP privada, que es el caso de Traefik dentro de la red de Docker. Un
| atacante en internet **no puede** presentarse con una IP de origen privada, así
| que no puede falsificar su procedencia para saltarse los límites.
|
| Confiar en `*` sería más simple pero abre exactamente ese agujero si algún día
| el contenedor queda accesible de forma directa.
|
| ---------------------------------------------------------------------------
| Si se pone Cloudflare delante
| ---------------------------------------------------------------------------
|
| La cadena pasa a ser: cliente → Cloudflare → Traefik → aplicación, y la
| cabecera llega como `X-Forwarded-For: <cliente>, <ip-de-cloudflare>`. Symfony
| la recorre de derecha a izquierda saltándose los proxies de confianza: si los
| rangos de Cloudflare **no** están en esta lista, se detendrá en su IP y la
| tomará por la del cliente — con lo que todo el tráfico volvería a agruparse.
|
| Hay que añadir los rangos publicados en https://www.cloudflare.com/ips/
| a `TRUSTED_PROXIES`, **junto con** los privados de aquí abajo.
|
*/

return [

    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'TRUSTED_PROXIES',
            '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
        ))
    ))),

];
