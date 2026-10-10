<?php

declare(strict_types=1);

use CalculadoraImportacion\Anuncio\Analizador;
use CalculadoraImportacion\Anuncio\Valores;

/**
 * Fixtures de tests/fixtures/anuncios/: cada una tiene su .html o .txt y un .json con lo esperado.
 * Son SINTÉTICAS (ver el README de la carpeta): ningún test hace peticiones a mobile.de.
 */
function lectorPrueba(): Analizador
{
    static $a = null;
    return $a ??= Analizador::crear(__DIR__ . '/../../data');
}

function fixturesAnuncios(): array
{
    $dir = __DIR__ . '/../fixtures/anuncios';
    $r = [];
    // Las sintéticas, y las páginas reales que se vayan guardando en reales/
    foreach (array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/reales/*.json') ?: []) as $json) {
        $base = substr($json, 0, -5);
        $fichero = is_file($base . '.html') ? $base . '.html' : $base . '.txt';
        $r[basename(dirname($json)) === 'reales' ? 'reales/' . basename($base) : basename($base)] = [
            'contenido' => (string) file_get_contents($fichero), 'esperado' => json_decode((string) file_get_contents($json), true),
        ];
    }
    ksort($r);
    return $r;
}

prueba('L1. Hay al menos 30 fixtures de anuncios, de varios estilos de página y de texto pegado', function (): void {
    $f = fixturesAnuncios();
    Afirmar::verdadero(count($f) >= 30, count($f) . ' fixtures');
    $html = count(array_filter($f, static fn(array $x): bool => $x['esperado']['fuente'] === 'html'));
    $texto = count($f) - $html;
    Afirmar::verdadero($html >= 25 && $texto >= 8, "$html html y $texto texto");
});

prueba('L2. Todas las fixtures: cada dato esperado se lee con su valor exacto y los que no están, no se inventan', function (): void {
    $fallos = [];
    foreach (fixturesAnuncios() as $nombre => $f) {
        $r = lectorPrueba()->analizarTexto($f['contenido'], '2026-10-10');
        $e = $f['esperado'];
        if ($r['fuente'] !== $e['fuente']) {
            $fallos[] = "$nombre: fuente {$r['fuente']} en vez de {$e['fuente']}";
        }
        foreach ($e['campos'] as $campo => $valor) {
            $leido = $r['campos'][$campo]['valor'] ?? null;
            if ($leido !== $valor) {
                $fallos[] = "$nombre: $campo = " . var_export($leido, true) . ' en vez de ' . var_export($valor, true);
            }
        }
        // Lo que no está en lo esperado no puede haberse rellenado, salvo la versión (sale del título) y los datos meramente
        // informativos de la tarjeta (cambio y ciudad), que se comprueban aparte en L7
        foreach (array_diff(array_keys($r['campos']), array_keys($e['campos']), ['version', 'cambio', 'ciudad_vendedor']) as $sobra) {
            $fallos[] = "$nombre: se ha inventado $sobra = " . var_export($r['campos'][$sobra]['valor'], true);
        }
        $dudosos = $r['dudosos'];
        sort($dudosos);
        if ($dudosos !== $e['dudosos']) {
            $fallos[] = "$nombre: a revisar [" . implode(',', $dudosos) . '] en vez de [' . implode(',', $e['dudosos']) . ']';
        }
        $faltan = $r['faltan'];
        sort($faltan);
        if ($faltan !== $e['faltan']) {
            $fallos[] = "$nombre: faltan [" . implode(',', $faltan) . '] en vez de [' . implode(',', $e['faltan']) . ']';
        }
        foreach ($e['avisos_contienen'] as $trozo) {
            if (!str_contains(implode(' | ', $r['avisos']), $trozo)) {
                $fallos[] = "$nombre: falta un aviso con «$trozo»";
            }
        }
    }
    Afirmar::igual([], $fallos, count($fallos) . ' diferencias');
});

prueba('L3. Los datos que salen siempre son valores limpios: números, listas cerradas y textos sin marcado', function (): void {
    foreach (fixturesAnuncios() as $nombre => $f) {
        $r = lectorPrueba()->analizarTexto($f['contenido'], '2026-10-10');
        foreach ($r['campos'] as $campo => $d) {
            $v = $d['valor'];
            Afirmar::verdadero($d['confianza'] >= 0.0 && $d['confianza'] <= 1.0, "$nombre $campo confianza");
            Afirmar::verdadero(in_array($d['origen'], ['estructurado', 'etiqueta', 'título', 'regex', 'derivado'], true), "$nombre $campo origen {$d['origen']}");
            match ($campo) {
                'km', 'cc', 'cp_vendedor', 'cv' => Afirmar::verdadero(preg_match('/^\d{1,7}$/', $v) === 1, "$nombre $campo=$v"),
                'kw', 'co2', 'precio' => Afirmar::verdadero(preg_match('/^\d{1,8}(,\d{1,2})?$/', $v) === 1, "$nombre $campo=$v"),
                'matriculacion' => Afirmar::verdadero(preg_match('#^(0[1-9]|1[0-2])/(19|20)\d{2}$#', $v) === 1, "$nombre $campo=$v"),
                'combustible' => Afirmar::verdadero(in_array($v, ['gasolina', 'diesel', 'electrico', 'hibrido', 'phev', 'glp', 'gnc', 'hidrogeno', 'etanol'], true), "$nombre $campo=$v"),
                'vendedor' => Afirmar::verdadero(in_array($v, ['particular', 'profesional'], true), "$nombre $campo=$v"),
                'iva_deducible' => Afirmar::igual('1', $v, "$nombre $campo"),
                default => Afirmar::verdadero(mb_strlen($v) <= 80 && !preg_match('/[<>\x00-\x1f]/', $v), "$nombre $campo='" . mb_substr($v, 0, 30) . "'"),
            };
        }
    }
});

prueba('L4. Contenido hostil: marcado en el título, textos ocultos, scripts y títulos enormes no cuelan nada', function (): void {
    $f = fixturesAnuncios()['18-contenido-hostil'];
    $r = lectorPrueba()->analizarTexto($f['contenido'], '2026-10-10');
    foreach ($r['campos'] as $d) {
        Afirmar::falso(str_contains($d['valor'], '<'), 'sin etiquetas: ' . $d['valor']);
        Afirmar::falso(str_contains($d['valor'], 'onerror'));
    }
    Afirmar::verdadero(mb_strlen($r['campos']['version']['valor']) <= 80);
    Afirmar::igual('105000', $r['campos']['km']['valor'], 'el kilometraje oculto con 1 km no se lee');
    Afirmar::igual('profesional' === ($r['campos']['vendedor']['valor'] ?? '') ? 'x' : 'particular', $r['campos']['vendedor']['valor'], 'la palabra «Händler» de un script o un menú no cambia el vendedor');
    // Entrada gigante: no revienta ni tarda
    $t = microtime(true);
    $grande = lectorPrueba()->analizarTexto(str_repeat("Kilometerstand\n12 km\nLeistung\n1 kW\n<div><span>x</span></div>\n", 20000), '2026-10-10');
    Afirmar::verdadero(microtime(true) - $t < 15, 'tarda ' . round(microtime(true) - $t, 1) . ' s');
    Afirmar::verdadero(is_array($grande['campos']));
    // Basura binaria y vacío
    Afirmar::igual([], lectorPrueba()->analizarTexto('', '2026-10-10')['campos']);
    Afirmar::igual([], lectorPrueba()->analizarTexto("\x00\x01\x02\xff\xfe", '2026-10-10')['campos']);
    Afirmar::igual([], lectorPrueba()->analizarHtml('<html><body><<<>>>&&&</body', '2026-10-10')['campos']);
});

prueba('L5. Normalizadores de valores: números alemanes, unidades, fechas y precios', function (): void {
    Afirmar::igual(105000, Valores::kilometros('105.000 km'));
    Afirmar::igual(105000, Valores::kilometros('105 000 km'));
    Afirmar::igual(105000, Valores::kilometros('105,000 km'));
    Afirmar::igual(null, Valores::kilometros('3.000.000 km'));
    Afirmar::igual(['kw' => 140.0, 'cv' => 190], Valores::potencia('140 kW (190 PS)'));
    Afirmar::igual(['kw' => 140.0, 'cv' => 190], Valores::potencia('190 PS (140 kW)'));
    Afirmar::igual(['kw' => 85.5], Valores::potencia('85,5 kW'));
    Afirmar::igual(1968, Valores::cilindrada('1.968 cm³'));
    Afirmar::igual(2000, Valores::cilindrada('2,0 l'));
    Afirmar::igual(['anio' => 2019, 'mes' => 3], Valores::matriculacion('03/2019'));
    Afirmar::igual(['anio' => 2019, 'mes' => 3], Valores::matriculacion('März 2019'));
    Afirmar::igual(['anio' => 2019, 'mes' => 3], Valores::matriculacion('15.03.2019'));
    Afirmar::igual(['anio' => 2019, 'mes' => null], Valores::matriculacion('2019'));
    Afirmar::igual(null, Valores::matriculacion('13/2019'));
    Afirmar::igual(16500.0, Valores::precio('16.500 €'));
    Afirmar::igual(16500.0, Valores::precio('€ 16.500,-'));
    Afirmar::igual(16500.0, Valores::precio('16 500 EUR'));
    Afirmar::igual(13865.55, Valores::precio('13.865,55 €'));
    Afirmar::igual(null, Valores::precio('1,5 €'));
    Afirmar::igual(['plz' => '80331', 'ciudad' => 'München', 'pais' => 'DE'], Valores::ubicacion('DE-80331 München'));
    Afirmar::igual('AT', Valores::ubicacion('AT-1010 Wien')['pais']);
});

prueba('L6. El selector CSS simple de los ficheros de selectores se traduce a XPath, y lo complejo se descarta', function (): void {
    $x = fn(string $s) => \CalculadoraImportacion\Anuncio\Extractor::cssAXPath($s);
    Afirmar::igual('//h1', $x('h1'));
    Afirmar::igual("//*[@data-testid='prime-price']", $x("[data-testid='prime-price']"));
    Afirmar::igual("//*[contains(@class,'price')]", $x("[class*='price']"));
    Afirmar::igual("//meta[@property='og:title']/@content", $x("meta[property='og:title']"));
    Afirmar::igual("//*[@data-testid='lista']/*", $x("[data-testid='lista'] > *"));
    Afirmar::igual(null, $x('a:hover'));
    Afirmar::igual(null, $x("a[href='x'] ~ b"));
    Afirmar::igual(null, $x(''));
});

prueba('L7. Cambio y ciudad del vendedor (datos de la tarjeta del coche) se leen con sus palabras en alemán, inglés o español', function (): void {
    $f = fixturesAnuncios();
    $casos = [
        '01-dl-privado' => ['Automático', 'München'],
        '10-electrico-sin-cc-ni-co2' => ['Automático', 'Hamburg'],
        '16-etiquetas-en-ingles' => ['Manual', 'Leipzig'],
        '17-etiquetas-en-espanol' => ['Manual', 'München'],
        '31-texto-rotulo-y-valor-en-lineas' => ['Automático', 'München'],
        '15-sin-kilometraje' => ['Automático', 'München'],
    ];
    foreach ($casos as $nombre => [$cambio, $ciudad]) {
        $r = lectorPrueba()->analizarTexto($f[$nombre]['contenido'], '2026-10-10');
        Afirmar::igual($cambio, $r['campos']['cambio']['valor'] ?? null, "$nombre cambio");
        Afirmar::igual($ciudad, $r['campos']['ciudad_vendedor']['valor'] ?? null, "$nombre ciudad");
    }
    Afirmar::igual('Semiautomático', Valores::cambio('Halbautomatik'));
    Afirmar::igual('Automático', Valores::cambio('Automatik'));
    Afirmar::igual('Manual', Valores::cambio('Schaltgetriebe'));
    Afirmar::igual(null, Valores::cambio('k. A.'));
});
