Hola {{ $user->full_name ?? 'Usuario' }},

Recibimos una solicitud para restablecer la contraseña de tu cuenta en {{ $appName }}.

Para elegir una nueva, abrí este enlace:

{{ $resetUrl }}

¿No fuiste vos? Ignorá este correo: tu contraseña seguirá siendo la misma y
nadie podrá entrar con este enlace sin acceso a tu buzón.

Por seguridad, el enlace caduca en {{ $horas }} horas y solo se puede usar una vez.

--
{{ $appName }}
