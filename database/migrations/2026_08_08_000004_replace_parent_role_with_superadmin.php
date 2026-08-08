<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rehace el enum `user_type`: fuera `parent`, dentro `superadmin`.
 *
 * **`parent`** llevaba desde el diseño original como previsión de un portal de
 * acudientes que nunca se construyó: sin rutas, sin controladores y sin una
 * sola fila en producción (verificado antes de migrar). Mantenerlo obligaba a
 * documentarlo como «rol reservado» en el TFG y a arrastrarlo en cada
 * validación.
 *
 * **`superadmin`** cubre un hueco real: hasta ahora `GET /api/institutions`
 * estaba abierto a cualquier `admin` y **no filtraba por institución**, así que
 * el administrador de un centro listaba todos los centros del SaaS. La
 * separación correcta es que el operador de la plataforma sea **externo** a las
 * instituciones.
 *
 * Por eso el superadmin es el único rol con `institution_id = NULL`, y la
 * ausencia de institución es justo lo que le impide tocar datos académicos: los
 * modelos con `TenantScoped` exigen un `tenant_id` que él nunca tiene.
 *
 * PostgreSQL no permite quitar valores de un enum nativo, así que el tipo se
 * recrea entero y se recablea la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cinturón: si alguien creó un `parent` entre el análisis y el despliegue,
        // la migración para en vez de perder la fila en el cast.
        $parents = DB::table('users')->where('user_type', 'parent')->count();

        if ($parents > 0) {
            throw new RuntimeException(
                "Hay {$parents} usuario(s) con rol 'parent'. Reasignarlos antes de migrar: " .
                "el rol desaparece y el cast al enum nuevo fallaría."
            );
        }

        DB::statement("CREATE TYPE user_type_nuevo AS ENUM ('superadmin', 'admin', 'teacher', 'student')");

        DB::statement(
            'ALTER TABLE users
                ALTER COLUMN user_type TYPE user_type_nuevo
                USING user_type::text::user_type_nuevo'
        );

        DB::statement('DROP TYPE user_type');
        DB::statement('ALTER TYPE user_type_nuevo RENAME TO user_type');
    }

    public function down(): void
    {
        // Al revertir, un superadmin no tiene equivalente: se queda sin rol
        // válido, así que se bloquea igual que en el sentido contrario.
        $supers = DB::table('users')->where('user_type', 'superadmin')->count();

        if ($supers > 0) {
            throw new RuntimeException(
                "Hay {$supers} superadmin(s). Eliminarlos antes de revertir: el rol no existe en el enum anterior."
            );
        }

        DB::statement("CREATE TYPE user_type_viejo AS ENUM ('admin', 'teacher', 'student', 'parent')");

        DB::statement(
            'ALTER TABLE users
                ALTER COLUMN user_type TYPE user_type_viejo
                USING user_type::text::user_type_viejo'
        );

        DB::statement('DROP TYPE user_type');
        DB::statement('ALTER TYPE user_type_viejo RENAME TO user_type');
    }
};
