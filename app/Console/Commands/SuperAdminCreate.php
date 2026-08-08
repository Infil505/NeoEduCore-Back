<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Admin\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
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
                            {--password= : Contraseña (si se omite, se pide por consola)}';

    protected $description = 'Crea un superadmin (operador de la plataforma, externo a las instituciones)';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Correo');
        $name  = $this->option('name') ?: $this->ask('Nombre completo');

        $password = $this->option('password') ?: $this->secret('Contraseña');

        $validator = Validator::make(
            ['email' => $email, 'full_name' => $name, 'password' => $password],
            [
                'email'     => ['required', 'email', 'max:120', 'unique:users,email'],
                'full_name' => ['required', 'string', 'min:3', 'max:120'],
                'password'  => ['required', Password::min(8)->letters()->numbers()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            // Sin institución: es lo que le cierra el acceso a los datos
            // académicos, porque TenantScoped exige un tenant_id que no tendrá.
            'institution_id' => null,
            'email'          => strtolower(trim($email)),
            'full_name'      => trim($name),
            'user_type'      => UserType::SuperAdmin->value,
            'status'         => UserStatus::Active->value,
            'password_hash'  => Hash::make($password),
        ]);

        $this->info("Superadmin creado: {$user->email} ({$user->id})");
        $this->line('Alcance: CRUD de instituciones y de sus administradores. Nada más.');

        return self::SUCCESS;
    }
}
