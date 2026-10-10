<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/**
 * Lo que enseña la tarjeta resumen de un coche (src/Vistas/tarjeta_coche.php) a partir de los valores del formulario:
 * título, versión, precio y las siete casillas de datos, marcando las que conviene revisar o faltan.
 */
final class TarjetaCoche
{
    public const COMBUSTIBLES = [
        'gasolina' => 'Gasolina', 'diesel' => 'Diésel', 'hibrido' => 'Híbrido', 'phev' => 'Híbrido enchufable',
        'electrico' => 'Eléctrico', 'glp' => 'GLP', 'gnc' => 'GNC',
    ];

    /**
     * @param array<string, string> $v valores en forma de formulario (Formulario::CAMPOS)
     * @param array<string, string> $revisar campo => motivo, de la lectura del anuncio
     * @return array{titulo: string, version: string, precio: ?float, datos: list<array{clave: string, etiqueta: string, icono: string, valor: ?string, revisar: bool}>}
     */
    public static function desdeValores(array $v, array $revisar = []): array
    {
        $texto = static fn(string $k): string => trim((string) ($v[$k] ?? ''));
        $km = Numero::desdeTexto($texto('km'));
        $kw = Numero::desdeTexto($texto('kw'));
        $cv = Numero::desdeTexto($texto('cv'));
        $potencia = null;
        if ($cv !== null && $kw !== null) {
            $potencia = Vista::num($cv) . ' CV (' . Vista::num($kw, $kw == floor($kw) ? 0 : 1) . ' kW)';
        } elseif ($cv !== null) {
            $potencia = Vista::num($cv) . ' CV';
        } elseif ($kw !== null) {
            $potencia = Vista::num($kw, $kw == floor($kw) ? 0 : 1) . ' kW';
        }
        $mat = preg_match('#^(\d{1,2})\s*/\s*(\d{4})$#', $texto('matriculacion'), $m) ? sprintf('%02d/%s', (int) $m[1], $m[2]) : null;
        $ciudad = $texto('ciudad_vendedor');
        $cp = $texto('cp_vendedor');
        $ubicacion = $ciudad !== '' ? $ciudad . ' (DE)' : ($cp !== '' ? 'CP ' . $cp . ' (DE)' : null);
        $vendedor = ['particular' => 'Particular', 'profesional' => 'Profesional'][$texto('vendedor')] ?? null;
        $combustible = self::COMBUSTIBLES[$texto('combustible')] ?? null;

        $casillas = [
            ['km', 'Kilómetros', 'km', $km !== null ? Vista::num($km) . ' km' : null, ['km']],
            ['potencia', 'Potencia', 'potencia', $potencia, ['kw', 'cv']],
            ['combustible', 'Combustible', 'combustible', $combustible, ['combustible']],
            ['matriculacion', 'Matriculación', 'calendario', $mat, ['matriculacion']],
        ];
        if ($texto('cambio') !== '') {
            $casillas[] = ['cambio', 'Cambio', 'cambio', $texto('cambio'), ['cambio']];
        }
        $casillas[] = ['ubicacion', 'Ubicación del vendedor', 'lugar', $ubicacion, ['cp_vendedor', 'ciudad_vendedor']];
        $casillas[] = ['vendedor', 'Vendedor', 'persona', $vendedor, ['vendedor']];

        $datos = [];
        foreach ($casillas as [$clave, $etiqueta, $icono, $valor, $campos]) {
            $dudoso = false;
            foreach ($campos as $c) {
                $dudoso = $dudoso || isset($revisar[$c]);
            }
            $datos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'icono' => $icono, 'valor' => $valor, 'revisar' => $dudoso || $valor === null];
        }
        $titulo = trim($texto('marca') . ' ' . $texto('modelo'));
        return [
            'titulo' => $titulo !== '' ? $titulo : 'Tu coche',
            'version' => $texto('version'),
            'precio' => Numero::desdeTexto($texto('precio')),
            'datos' => $datos,
        ];
    }
}
