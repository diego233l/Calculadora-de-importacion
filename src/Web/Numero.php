<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/** Lectura de números escritos a mano por el usuario, con la costumbre española (coma decimal, punto de millares). */
final class Numero
{
    /**
     * "14.000" -> 14000 · "14.000,50" -> 14000.5 · "1,5" -> 1.5 · "1.5" -> 1.5 · "14,000.50" -> 14000.5 · "1 998" -> 1998
     * Devuelve null si no es un número (letras, signos raros, varios separadores desordenados).
     */
    public static function desdeTexto(string $texto): ?float
    {
        $t = str_replace(["\u{00a0}", "\u{202f}", ' '], '', trim($texto));
        $t = preg_replace('/(€|eur|euros|km|cm3|cm³|cc|cv|kw|g\/km)$/i', '', $t) ?? '';
        if ($t === '' || !preg_match('/^\d[\d.,]*$/', $t)) {
            return null;
        }
        $puntos = substr_count($t, '.');
        $comas = substr_count($t, ',');
        if ($puntos > 0 && $comas > 0) {
            // El último separador es el decimal; el otro, el de millares
            $decimal = strrpos($t, ',') > strrpos($t, '.') ? ',' : '.';
            $millares = $decimal === ',' ? '.' : ',';
            // «14.000,50»: grupos de tres cifras separados por el de millares y un único decimal al final
            $patron = '/^\d{1,3}(?:' . preg_quote($millares, '/') . '\d{3})+' . preg_quote($decimal, '/') . '\d+$/';
            if (!preg_match($patron, $t)) {
                return null;
            }
            $t = str_replace($millares, '', $t);
            $t = str_replace($decimal, '.', $t);
        } elseif ($puntos > 0) {
            // Un punto con exactamente tres cifras detrás y nada raro delante son millares (14.000 / 1.998.000)
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $t)) {
                $t = str_replace('.', '', $t);
            } elseif ($puntos > 1) {
                return null;
            }
        } elseif ($comas > 0) {
            if ($comas > 1) {
                return null;
            }
            $t = str_replace(',', '.', $t);
        }
        return is_numeric($t) ? (float) $t : null;
    }
}
