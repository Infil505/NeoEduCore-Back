<?php

namespace App\Services\Imports;

/** Se lanza al final de una carga simulada para que la transacción se deshaga. */
class SimulacionRevertida extends \RuntimeException
{
}
