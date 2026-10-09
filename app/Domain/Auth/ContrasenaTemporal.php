<?php

namespace App\Domain\Auth;

/**
 * Contraseña temporal para una cuenta recién creada.
 *
 * Aleatoria (CSPRNG) y distinta para cada cuenta: nadie puede deducir la de otro.
 * Cumple siempre `PasswordPolicy` (mayúscula, minúscula y número) y evita los
 * caracteres que se confunden al leerlos en un correo o en una hoja impresa
 * (0/O, 1/l/I), porque la va a teclear a mano alguien de 6 a 12 años o un
 * docente que la recibió en un correo.
 */
class ContrasenaTemporal
{
    private const MAYUSCULAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const MINUSCULAS = 'abcdefghijkmnopqrstuvwxyz';
    private const NUMEROS    = '23456789';

    public static function generar(int $largo = 10): string
    {
        $largo = max(8, $largo);
        $todo  = self::MAYUSCULAS . self::MINUSCULAS . self::NUMEROS;

        // Una de cada clase garantizada; el resto, del conjunto completo.
        $caracteres = [
            self::tomar(self::MAYUSCULAS),
            self::tomar(self::MINUSCULAS),
            self::tomar(self::NUMEROS),
        ];
        while (count($caracteres) < $largo) {
            $caracteres[] = self::tomar($todo);
        }

        // Fisher-Yates con random_int: la posición de las tres obligatorias no
        // queda fija.
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
        }

        return implode('', $caracteres);
    }

    private static function tomar(string $conjunto): string
    {
        return $conjunto[random_int(0, strlen($conjunto) - 1)];
    }
}
