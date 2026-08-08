Hola {{ $user->full_name ?? 'Usuario' }},

Se creó tu cuenta en {{ $appName }}. Para activarla necesitás establecer tu
contraseña abriendo este enlace:

{{ $setupUrl }}

Si no esperabas este correo, podés ignorarlo.

Por seguridad, el enlace caduca en {{ $horas }} horas y solo se puede usar una vez.

--
{{ $appName }}
