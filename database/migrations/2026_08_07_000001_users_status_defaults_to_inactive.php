<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.status` pasa a tener `DEFAULT 'inactive'`.
 *
 * A partir del 07/08/2026 una cuenta nace **inactiva** y se activa cuando su
 * dueño define la contraseña desde el enlace que recibe por correo. Hasta ahora
 * la columna venía con `DEFAULT 'active'`, lo que dejaba una trampa: cualquier
 * inserción que omitiera `status` creaba una cuenta activa y utilizable, justo
 * lo contrario de la regla nueva.
 *
 * **No toca ninguna fila existente**: cambiar un DEFAULT solo afecta a las
 * inserciones futuras, así que las cuentas ya activas en producción siguen
 * igual. Tampoco cambia el comportamiento del código actual, que pasa `status`
 * de forma explícita en las dos vías de alta; es una salvaguarda para lo que
 * venga después.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN status SET DEFAULT 'inactive'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN status SET DEFAULT 'active'");
    }
};
