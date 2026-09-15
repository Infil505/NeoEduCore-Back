<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Admin\User;
use App\Services\Auth\PasswordSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Crea un superadmin.
 *
 * Existe porque **ninguna ruta de la API puede crear uno**, y es deliberado: el
 * superadmin gestiona instituciones y sus administradores, no otros
 * superadmins, y `/register` solo asigna roles de institución. Sin esta vía no
 * habría forma de arrancar la plataforma; con ella, dar de alta un operador
 * exige acceso al servidor, no una sesión con un token.
 *
 *   php artisan superadmin:create --email=ops@ejemplo.com --name="Operaciones"
 *
 * La contraseña se pide por consola (oculta) si no se pasa `--password`.
 */
class SuperAdminCreate extends Command
{
    protected $signature = 'superadmin:create
                            {--email= : Correo del superadmin}
                            {--name= : Nombre completo}
                            {--password= : Contraseña (si se omite, se pide por consola)}
                            {--send-setup-link : No pide contraseña: crea la cuenta inactiva y envía el enlace para que su dueño la defina}';

    protected $description = 'Crea un superadmin (operador de la plataforma, externo a las instituciones)';

    public function handle(PasswordSetupService $passwordSetup): int
    {
        $email = $this->option('email') ?: $this->ask('Correo');
        $name  = $this->option('name') ?: $this->ask('Nombre completo');

        $porEnlace = (bool) $this->option('send-setup-link');

        // Con --send-setup-link no existe contraseña en ningún momento: ni en el
        // historial de shell, ni en los logs, ni en la memoria del proceso.
        $password = $porEnlace ? null : ($this->option('password') ?: $this->secret('Contraseña'));

        $reglas = [
            'email'     => ['required', 'email', 'max:120', 'unique:users,email'],
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
        ];

        if (!$porEnlace) {
            $reglas['password'] = ['required', Password::min(8)->letters()->numbers()];
        }

        $validator = Validator::make(
            ['email' => $email, 'full_name' => $name, 'password' => $password],
            $reglas
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // El enlace del correo se arma con `url()`, que sale de APP_URL. Ejecutar
        // esto desde una máquina de desarrollo contra la base de producción
        // generaría un correo con un enlace a http://localhost: válido como
        // token, inservible como enlace. Se crea la cuenta igual —eso no depende
        // de la URL— pero el envío se detiene y se explica la alternativa.
        $urlLocal = $this->appUrlEsLocal();

        $user = User::create([
            // Sin institución: es lo que le cierra el acceso a los datos
            // académicos, porque TenantScoped exige un tenant_id que no tendrá.
            'institution_id' => null,
            'email'          => strtolower(trim($email)),
            'full_name'      => trim($name),
            'user_type'      => UserType::SuperAdmin->value,
            // Por enlace nace INACTIVA: la activa su dueño al definir la
            // contraseña, igual que las cuentas de la carga masiva.
            'status'         => $porEnlace ? UserStatus::Inactive->value : UserStatus::Active->value,
            // Contraseña no usable mientras no la defina su dueño.
            'password_hash'  => Hash::make($porEnlace ? Str::random(40) : $password),
        ]);

        $this->info("Superadmin creado: {$user->email} ({$user->id})");
        $this->line('Alcance: CRUD de instituciones y de sus administradores. Nada más.');

        if (!$porEnlace) {
            return self::SUCCESS;
        }

        if ($urlLocal) {
            $this->newLine();
            $this->warn('Correo NO enviado: APP_URL es «' . config('app.url') . '».');
            $this->line('El enlace se arma con esa URL, así que llegaría apuntando a localhost.');
            $this->line('La cuenta queda creada e inactiva. Para activarla, desde el servidor');
            $this->line('desplegado (con su APP_URL real): POST /api/password/forgot con este correo.');

            return self::SUCCESS;
        }

        if ($passwordSetup->sendSetupLink($user)) {
            $this->line('Enlace de activación encolado a ' . $user->email . '.');
            $this->line('Ojo: QUEUE_CONNECTION=' . config('queue.default') . ' — requiere un worker corriendo.');
        } else {
            $this->error('La cuenta se creó, pero el envío del enlace falló. Reintentar con POST /api/password/forgot.');
        }

        return self::SUCCESS;
    }

    /** ¿APP_URL apunta a una máquina de desarrollo? */
    private function appUrlEsLocal(): bool
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';

        return in_array($host, ['localhost', '127.0.0.1', '::1', ''], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }
}
