<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Anuncio;

/**
 * Convierte el texto de un dato del anuncio en un valor: «105.000 km» -> 105000, «110 kW (150 PS)» -> kW y CV,
 * «03/2019» -> 2019-03… Funciones puras. Devuelven null si el texto no es ese dato; nunca inventan.
 */
final class Valores
{
    private const MESES = [
        'januar' => 1, 'jänner' => 1, 'janner' => 1, 'january' => 1, 'enero' => 1, 'februar' => 2, 'february' => 2, 'febrero' => 2,
        'märz' => 3, 'marz' => 3, 'march' => 3, 'marzo' => 3, 'april' => 4, 'abril' => 4, 'mai' => 5, 'may' => 5, 'mayo' => 5,
        'juni' => 6, 'june' => 6, 'junio' => 6, 'juli' => 7, 'july' => 7, 'julio' => 7, 'august' => 8, 'agosto' => 8,
        'september' => 9, 'septiembre' => 9, 'setiembre' => 9, 'oktober' => 10, 'october' => 10, 'octubre' => 10,
        'november' => 11, 'noviembre' => 11, 'dezember' => 12, 'december' => 12, 'diciembre' => 12,
    ];

    /** Entero con separador de millares (punto, coma o espacio): «105.000», «105,000», «105 000», «105000». */
    public static function entero(string $t): ?int
    {
        $t = trim(str_replace(["\u{00a0}", "\u{202f}", "\u{2009}"], ' ', $t));
        if (preg_match('/^\d{1,3}(?:[.,\' ]\d{3})+$/', $t)) {
            return (int) preg_replace('/\D/', '', $t);
        }
        return preg_match('/^\d{1,9}$/', $t) ? (int) $t : null;
    }

    /** Decimal al estilo alemán o inglés: «119,5», «119.5», «1.234,5». */
    public static function decimal(string $t): ?float
    {
        $t = trim(str_replace(["\u{00a0}", "\u{202f}", ' '], '', $t));
        if (preg_match('/^\d{1,3}(?:\.\d{3})+,\d+$/', $t)) {
            return (float) str_replace(['.', ','], ['', '.'], $t);
        }
        if (preg_match('/^\d{1,3}(?:,\d{3})+\.\d+$/', $t)) {
            return (float) str_replace(',', '', $t);
        }
        if (preg_match('/^\d+(?:[.,]\d+)?$/', $t)) {
            return (float) str_replace(',', '.', $t);
        }
        return null;
    }

    /** «105.000 km» -> 105000 */
    public static function kilometros(string $t): ?int
    {
        if (!preg_match('/(?<![\d.,])(\d{1,3}(?:[.,\' \x{00a0}\x{202f}]\d{3})+|\d{1,7})\s*(?:km|kilometer|kilómetros|kilometros)\b/iu', $t, $m)) {
            // Un número suelto («105.000») es válido cuando el rótulo ya era «Kilometerstand»
            return preg_match('/^\s*(\d{1,3}(?:[.,\' ]\d{3})+|\d{1,7})\s*$/', $t, $m) ? self::entero($m[1]) : null;
        }
        $n = self::entero($m[1]);
        return $n !== null && $n <= 2_000_000 ? $n : null;
    }

    /** «110 kW (150 PS)», «150 PS (110 kW)», «110 kW / 150 hp» -> ['kw' => 110.0, 'cv' => 150] (cada uno si aparece). @return array{kw?: float, cv?: int}|null */
    public static function potencia(string $t): ?array
    {
        $r = [];
        if (preg_match('/(?<![\d.,])(\d{1,4}(?:[.,]\d{1,2})?)\s*kW\b/iu', $t, $m)) {
            $kw = self::decimal($m[1]);
            if ($kw !== null && $kw > 0 && $kw <= 2000) {
                $r['kw'] = $kw;
            }
        }
        if (preg_match('/(?<![\d.,])(\d{1,4})\s*(?:PS|CV|hp|bhp)\b/iu', $t, $m)) {
            $cv = (int) $m[1];
            if ($cv > 0 && $cv <= 3000) {
                $r['cv'] = $cv;
            }
        }
        return $r === [] ? null : $r;
    }

    /** «1.968 cm³», «1968 ccm», «2.0 l» -> 1968, 1968, 2000 */
    public static function cilindrada(string $t): ?int
    {
        if (preg_match('/(?<![\d.,])(\d{1,2}[.,\' \x{00a0}]?\d{3}|\d{3})\s*(?:ccm|cm³|cm3|cm\x{00b3}|cc)\b/iu', $t, $m)) {
            $n = (int) preg_replace('/\D/', '', $m[1]);
            return $n >= 50 && $n <= 20000 ? $n : null;
        }
        if (preg_match('/(?<![\d.,])(\d{1,2}[.,]\d)\s*(?:l|liter|litros|litres)\b/iu', $t, $m)) {
            return (int) round(((float) str_replace(',', '.', $m[1])) * 1000);
        }
        if (preg_match('/^\s*(\d{1,2}[.,\' ]?\d{3}|\d{3})\s*$/', $t, $m)) {
            $n = (int) preg_replace('/\D/', '', $m[1]);
            return $n >= 50 && $n <= 20000 ? $n : null;
        }
        return null;
    }

    /**
     * Fecha de primera matriculación. @return array{anio: int, mes: ?int}|null  (mes null si solo se sabe el año)
     */
    public static function matriculacion(string $t): ?array
    {
        $t = trim($t);
        if (preg_match('#(?<!\d)(0?[1-9]|1[0-2])\s*[/.\-]\s*((?:19|20)\d{2})(?!\d)#', $t, $m)) {
            return ['anio' => (int) $m[2], 'mes' => (int) $m[1]];
        }
        if (preg_match('#(?<!\d)((?:19|20)\d{2})\s*[/.\-]\s*(0?[1-9]|1[0-2])(?!\d)#', $t, $m)) {
            return ['anio' => (int) $m[1], 'mes' => (int) $m[2]];
        }
        if (preg_match('#(?<!\d)(?:0?[1-9]|[12]\d|3[01])\s*[.\-/]\s*(0?[1-9]|1[0-2])\s*[.\-/]\s*((?:19|20)\d{2})(?!\d)#', $t, $m)) {
            return ['anio' => (int) $m[2], 'mes' => (int) $m[1]];
        }
        $texto = mb_strtolower($t, 'UTF-8');
        foreach (self::MESES as $nombre => $mes) {
            if (preg_match('/(?<![a-zäöüéñ])' . preg_quote($nombre, '/') . '\.?\s*(?:de\s+|del\s+)?((?:19|20)\d{2})(?!\d)/u', $texto, $m)) {
                return ['anio' => (int) $m[1], 'mes' => $mes];
            }
        }
        if (preg_match('#^\D{0,12}((?:19|20)\d{2})\D{0,3}$#', $t, $m)) {
            return ['anio' => (int) $m[1], 'mes' => null];
        }
        return null;
    }

    /** «16.500 €», «€ 16.500», «16.500,- EUR», «16 500 Euro», «€16,500.00» -> 16500.0 */
    public static function precio(string $t): ?float
    {
        $num = '(\d{1,3}(?:[.,\' \x{00a0}\x{202f}]\d{3})+(?:[.,]\d{1,2}|[.,]-)?|\d{1,8}(?:[.,]\d{1,2}|[.,]-)?)';
        if (!preg_match('/(?:€|EUR\b|Euro\b)\s*' . $num . '(?!\d)|(?<![\d.,])' . $num . '\s*(?:€|EUR\b|Euro\b)/iu', $t, $m)) {
            return null;
        }
        $n = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        $n = preg_replace('/[.,]-$/', '', $n) ?? $n;
        if (!preg_match('/^(\d{1,3}(?:[.,\' \x{00a0}\x{202f}]\d{3})*|\d+)(?:[.,](\d{1,2}))?$/u', $n, $p)) {
            return null;
        }
        $v = (float) preg_replace('/\D/', '', $p[1]) + (isset($p[2]) && $p[2] !== '' ? ((float) $p[2]) / (10 ** strlen($p[2])) : 0.0);
        return $v >= 100 && $v <= 10_000_000 ? $v : null;
    }

    /** «119 g/km», «119,5 g/km», «119» -> 119.0 */
    public static function co2(string $t): ?float
    {
        // Con la unidad, el número que la precede; sin ella, solo vale si el texto es un número y nada más
        if (!preg_match('/(?<![\d.,])(\d{1,4}(?:[.,]\d{1,2})?)\s*g\s*\/\s*km/iu', $t, $m) && !preg_match('/^\s*(\d{1,4}(?:[.,]\d{1,2})?)\s*$/', $t, $m)) {
            return null;
        }
        $v = self::decimal($m[1]);
        return $v !== null && $v >= 0 && $v <= 1000 ? $v : null;
    }

    /**
     * Ubicación del vendedor: «DE-80331 München», «80331 München», «D-80331 München, Deutschland».
     *
     * @return array{plz: string, ciudad: ?string, pais: string}|null
     */
    public static function ubicacion(string $t): ?array
    {
        if (!preg_match('/(?<![\d])(?:(DE|D|AT|A|CH|NL|BE|FR|IT|ES|PL|CZ|LU|DK)\s*[-–]\s*)?(\d{4,5})(?![\d])\s*([^\d,;\n|]{2,40})?/u', $t, $m)) {
            return null;
        }
        $pais = $m[1] !== '' ? strtoupper($m[1]) : '';
        $pais = match ($pais) { 'D', '' => 'DE', 'A' => 'AT', default => $pais };
        if (strlen($m[2]) !== 5 && $pais === 'DE') {
            return null;                                  // el código postal alemán tiene cinco cifras
        }
        $ciudad = isset($m[3]) ? trim(preg_replace('/\s+/u', ' ', $m[3]) ?? '') : '';
        return ['plz' => $m[2], 'ciudad' => $ciudad !== '' ? $ciudad : null, 'pais' => $pais];
    }

    /** Texto corto y limpio para un campo libre (marca, modelo, versión): sin etiquetas, sin control, recortado. */
    public static function textoLibre(string $t, int $max = 80): string
    {
        $t = strip_tags($t);
        $t = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $t) ?? '';
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        return mb_substr($t, 0, $max);
    }
}
