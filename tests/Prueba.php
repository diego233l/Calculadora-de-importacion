<?php

declare(strict_types=1);

/**
 * Mini-framework de pruebas (sin dependencias): prueba('nombre', function () { ... });
 * Las aserciones lanzan AssertionError; run.php las recoge e imprime el resumen.
 */
final class Prueba
{
    /** @var array<int, array{string, callable}> */
    public static array $registro = [];
}

function prueba(string $nombre, callable $cuerpo): void
{
    Prueba::$registro[] = [$nombre, $cuerpo];
}

final class Afirmar
{
    public static function verdadero($valor, string $mensaje = ''): void
    {
        if ($valor !== true) {
            throw new \AssertionError($mensaje !== '' ? $mensaje : 'Se esperaba true y se obtuvo ' . var_export($valor, true));
        }
    }

    public static function falso($valor, string $mensaje = ''): void
    {
        if ($valor !== false) {
            throw new \AssertionError($mensaje !== '' ? $mensaje : 'Se esperaba false y se obtuvo ' . var_export($valor, true));
        }
    }

    public static function igual($esperado, $real, string $mensaje = ''): void
    {
        if ($esperado !== $real) {
            throw new \AssertionError(($mensaje !== '' ? $mensaje . ': ' : '') . 'esperado ' . var_export($esperado, true) . ' y obtenido ' . var_export($real, true));
        }
    }

    /** Compara importes en euros: coinciden si difieren menos de medio céntimo. */
    public static function euros(float $esperado, $real, string $mensaje = ''): void
    {
        if (!is_float($real) && !is_int($real)) {
            throw new \AssertionError(($mensaje !== '' ? $mensaje . ': ' : '') . 'esperado ' . $esperado . ' y obtenido ' . var_export($real, true));
        }
        if (abs($esperado - $real) >= 0.005) {
            throw new \AssertionError(($mensaje !== '' ? $mensaje . ': ' : '') . sprintf('esperado %.2f y obtenido %.4f', $esperado, $real));
        }
    }

    public static function contiene(string $aguja, string $pajar, string $mensaje = ''): void
    {
        if (!str_contains($pajar, $aguja)) {
            throw new \AssertionError(($mensaje !== '' ? $mensaje . ': ' : '') . "no se encuentra «{$aguja}» en «{$pajar}»");
        }
    }

    public static function lanza(string $clase, callable $codigo, string $mensaje = ''): \Throwable
    {
        try {
            $codigo();
        } catch (\Throwable $e) {
            if ($e instanceof $clase) {
                return $e;
            }
            throw new \AssertionError(($mensaje !== '' ? $mensaje . ': ' : '') . "se esperaba $clase y se lanzó " . get_class($e) . ': ' . $e->getMessage());
        }
        throw new \AssertionError(($mensaje !== '' ? $mensaje . ': ' : '') . "se esperaba que se lanzara $clase");
    }
}
