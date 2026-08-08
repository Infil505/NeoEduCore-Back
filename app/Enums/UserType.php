<?php

namespace App\Enums;

/**
 * Roles del sistema.
 *
 * `SuperAdmin` es **externo a las instituciones**: es el único rol con
 * `institution_id = NULL`, y esa ausencia es la que le cierra el acceso a los
 * datos académicos —los modelos con `TenantScoped` exigen un `tenant_id` que él
 * nunca tiene—. Su alcance se limita a dar de alta instituciones y a sus
 * administradores; a partir de ahí, cada centro se gestiona solo.
 *
 * El rol `Parent` se retiró el 08/08/2026: llevaba desde el diseño original sin
 * rutas, sin controladores y sin una sola fila en producción.
 */
enum UserType: string
{
    case SuperAdmin = 'superadmin';
    case Admin      = 'admin';
    case Teacher    = 'teacher';
    case Student    = 'student';

    /** Roles que pertenecen a una institución (todos menos el superadmin). */
    public function perteneceAInstitucion(): bool
    {
        return $this !== self::SuperAdmin;
    }

    /** @return array<int,string> valores asignables dentro de una institución */
    public static function rolesDeInstitucion(): array
    {
        return [self::Admin->value, self::Teacher->value, self::Student->value];
    }
}
