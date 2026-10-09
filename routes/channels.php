<?php

use Illuminate\Support\Facades\Broadcast;

/*
| Canal privado de un alumno: `private-alumno.{userId}`.
|
| Cada uno escucha SOLO el suyo: el id del canal tiene que ser el del usuario
| autenticado. Quién recibe cada aviso lo decide el emisor con las reglas de
| visibilidad del examen (`Exam::destinatarios()`), no este canal; aquí solo
| se impide que alguien escuche el de otro.
|
| La autenticación la hace `POST /api/broadcasting/auth` con el mismo token
| Bearer de la API, tras el middleware `activa` (cuenta y centro activos).
| Docentes y administradores no necesitan escuchar: emiten.
*/
Broadcast::channel('alumno.{userId}', function ($user, string $userId) {
    return $user->user_type === \App\Enums\UserType::Student
        && (string) $user->id === $userId;
});
