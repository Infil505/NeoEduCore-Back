<?php

/*
|--------------------------------------------------------------------------
| Tamaños de página
|--------------------------------------------------------------------------
|
| Antes había cinco valores distintos repartidos por los controladores —20, 30,
| 15, 10 y 50— sin criterio visible que los distinguiera. Aquí quedan los dos
| que sí responden a algo distinto:
|
| - `default`: listados de gestión, que se navegan a mano desde un panel.
| - `reports`: listados de resultados, que se leen de corrido o se exportan;
|   páginas más grandes reducen viajes sin coste apreciable, porque las filas
|   son estrechas.
|
| Consumirlos desde config y no literales permite bajarlos si un despliegue con
| poca memoria empieza a sufrir con instituciones grandes.
|
*/

return [
    'default' => (int) env('PAGINATION_DEFAULT', 20),
    'reports' => (int) env('PAGINATION_REPORTS', 50),
];
