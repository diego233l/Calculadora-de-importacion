<?php

declare(strict_types=1);

use CalculadoraImportacion\Datos\Tablas;
use CalculadoraImportacion\Web\Almacen;
use CalculadoraImportacion\Web\Config;
use CalculadoraImportacion\Web\Formulario;
use CalculadoraImportacion\Web\Municipios;
use CalculadoraImportacion\Web\Numero;
use CalculadoraImportacion\Web\Vista;

function tablasReales(): array
{
    static $t = null;
    return $t ??= Tablas::cargar(Config::dirData(), 2026);
}

prueba('X1. Números a la española: coma decimal, punto de millares y unidades al final', function (): void {
    $casos = [
        '14.000' => 14000.0, '14.000,50' => 14000.5, '1,5' => 1.5, '1.5' => 1.5, '14,000.50' => 14000.5, '1 998' => 1998.0,
        '125000' => 125000.0, '1.998.000' => 1998000.0, '0' => 0.0, '0,75' => 0.75, '18.000 €' => 18000.0, '80.000 km' => 80000.0,
        '1.968 cm³' => 1968.0, '150 CV' => 150.0, '170 g/km' => 170.0, "1\u{a0}998" => 1998.0, ' 42 ' => 42.0,
    ];
    foreach ($casos as $texto => $esperado) {
        Afirmar::igual($esperado, Numero::desdeTexto((string) $texto), "«{$texto}»");
    }
    foreach (['', 'abc', '-5', '1e5', '1,2,3', '1.2.3', '12a', '1..000', '1,.5', '€', '0x1A', '1.000,000,5'] as $malo) {
        Afirmar::igual(null, Numero::desdeTexto($malo), "«{$malo}» no es un número");
    }
});

prueba('X2. Municipios: resolver por código, por nombre exacto, con provincia y con ambigüedad', function (): void {
    $t = tablasReales();
    Afirmar::igual('28079', Municipios::resolver($t, '28079', '')['ine']);
    Afirmar::igual('28079', Municipios::resolver($t, '', 'Madrid')['ine']);
    Afirmar::igual('28079', Municipios::resolver($t, '', 'madrid (Madrid)')['ine']);
    Afirmar::igual('37274', Municipios::resolver($t, '', 'SALAMANCA')['ine']);
    Afirmar::igual('15030', Municipios::resolver($t, '', 'A Coruña')['ine']);
    Afirmar::igual('03014', Municipios::resolver($t, '', 'Alicante')['ine'], 'por una de las dos lenguas del nombre');
    Afirmar::igual('03014', Municipios::resolver($t, '', 'Alacant')['ine']);
    // Un código inventado o de otro formato no vale y se cae al texto
    Afirmar::igual('28079', Municipios::resolver($t, '99999', 'Madrid')['ine']);
    Afirmar::igual(null, Municipios::resolver($t, "28079' OR 1=1", '')['ine']);
    // No soportados: mensaje por nombre de comunidad
    $r = Municipios::resolver($t, '35016', '');
    Afirmar::igual(null, $r['ine']);
    Afirmar::contiene('Canarias', $r['error']);
    Afirmar::contiene('País Vasco', Municipios::resolver($t, '', 'Bilbao')['error']);
    Afirmar::contiene('Navarra', Municipios::resolver($t, '', 'Pamplona')['error']);
    // Vacío y desconocido
    Afirmar::contiene('Elige el municipio', Municipios::resolver($t, '', '')['error']);
    $r = Municipios::resolver($t, '', 'Zzzzz');
    Afirmar::igual([], $r['candidatos']);
    // Aproximado: devuelve candidatos
    $r = Municipios::resolver($t, '', 'Salamanc');
    Afirmar::igual('37274', $r['candidatos'][0]['ine']);
    // Varios con el mismo nombre: pide elegir. «Santa Cruz» es un nombre compuesto; buscamos uno realmente repetido
    $dup = [];
    foreach ($t['municipios'] as $ine => $m) {
        $dup[Municipios::normalizar($m['nombre'])][] = (string) $ine;
    }
    $repetidos = array_filter($dup, static fn(array $l): bool => count(array_filter($l, static fn(string $c): bool => Municipios::soportado(tablasReales(), $c))) > 1);
    Afirmar::verdadero($repetidos !== [], 'hay nombres repetidos en España');
    $nombre = $t['municipios'][array_key_first($repetidos) === null ? '' : reset($repetidos)[0]]['nombre'];
    $r = Municipios::resolver($t, '', $nombre);
    Afirmar::igual(null, $r['ine'], $nombre);
    Afirmar::verdadero(count($r['candidatos']) > 1);
    // Etiquetas
    Afirmar::igual('Madrid', Municipios::etiqueta($t, '28079'));
    Afirmar::igual('Salamanca', Municipios::etiqueta($t, '37274'));
    Afirmar::igual('Alacant/Alicante', Municipios::etiqueta($t, '03014'), 'misma provincia: sin paréntesis');
    Afirmar::igual('Getafe (Madrid)', Municipios::etiqueta($t, '28065'));
});

prueba('X3. Fechas de matriculación: formatos aceptados y rechazados', function (): void {
    $t = tablasReales();
    $leer = fn(string $m) => Formulario::leerDatos(datosCoche(['matriculacion' => $m]), $t, '2026-10-10');
    foreach (['03/2021' => '2021-03-01', '3/2021' => '2021-03-01', '03-2021' => '2021-03-01', '03.2021' => '2021-03-01',
              '2021-03' => '2021-03-01', '2021/3' => '2021-03-01', '2021-03-15' => '2021-03-01', ' 03 / 2021 ' => '2021-03-01',
              '10/2026' => '2026-10-01'] as $texto => $iso) {
        $f = $leer($texto);
        Afirmar::igual($iso, $f['entrada']['fecha_matriculacion'] ?? null, "«{$texto}»");
    }
    foreach (['11/2026', '2027-01', '00/2021', '13/2021', '3/21', '2021', 'marzo 2021', '', '03/1949', '03/1800', '99/9999'] as $malo) {
        Afirmar::verdadero(isset($leer($malo)['errores']['matriculacion']), "«{$malo}» debería rechazarse");
    }
});

prueba('X4. Formulario: eléctricos, IVA desglosable y valores de vuelta al editar', function (): void {
    $t = tablasReales();
    $f = Formulario::leerDatos(datosCoche(['vendedor' => 'particular', 'iva_deducible' => '1']), $t, '2026-10-10');
    Afirmar::falso($f['entrada']['iva_deducible'], 'el IVA desglosable solo cuenta con un vendedor profesional');
    $f = Formulario::leerDatos(datosCoche(['vendedor' => 'profesional', 'iva_deducible' => '1']), $t, '2026-10-10');
    Afirmar::verdadero($f['entrada']['iva_deducible']);
    // Un eléctrico descarta cilindrada, cilindros y CO2 aunque vengan rellenos
    $e = Formulario::leerDatos(datosCoche(['combustible' => 'electrico', 'kw' => '110', 'cc' => '1998', 'cilindros' => '4', 'co2' => '120']), $t, '2026-10-10');
    Afirmar::igual([], $e['errores']);
    Afirmar::falso(isset($e['entrada']['cc']) || isset($e['entrada']['cilindros']) || isset($e['entrada']['co2_wltp']));
    // Ida y vuelta: lo guardado vuelve al formulario con el mismo contenido
    $original = Formulario::leerDatos(datosCoche(), $t, '2026-10-10');
    $v = Formulario::valoresDesdeEntrada($original['entrada'], $t);
    Afirmar::igual('03/2021', $v['matriculacion']);
    Afirmar::igual('80000', $v['km']);
    Afirmar::igual('1968', $v['cc']);
    Afirmar::igual('170', $v['co2']);
    Afirmar::igual('18000', $v['precio']);
    Afirmar::igual('38000', $v['boe_precio']);
    Afirmar::igual('Madrid', $v['municipio_texto']);
    $otra = Formulario::leerDatos($v, $t, '2026-10-10');
    Afirmar::igual([], $otra['errores']);
    Afirmar::igual($original['entrada'], $otra['entrada'], 'editar sin tocar nada no cambia nada');
    // Textos largos se recortan
    $larga = Formulario::leerDatos(datosCoche(['marca' => str_repeat('A', 500)]), $t, '2026-10-10');
    Afirmar::verdadero(mb_strlen($larga['entrada']['marca']) <= 80);
});

prueba('X5. Almacén SQLite: guardar, leer, límites por ventana y purga', function (): void {
    $a = new Almacen(dirVarPrueba('almacen-x5'));
    $id = $a->guardarCalculo(['marca' => 'Ñandú <b>'], ['total' => 123.45, 'partidas' => []]);
    Afirmar::verdadero(Almacen::idValido($id));
    $c = $a->leerCalculo($id);
    Afirmar::igual('Ñandú <b>', $c['entrada']['marca']);
    Afirmar::igual(123.45, $c['resultado']['total']);
    Afirmar::igual(null, $a->leerCalculo('000000000000'));
    foreach (["x' OR '1'='1", '../../etc/passwd', str_repeat('a', 12) . "\n", 'ABCDEF123456', ''] as $malo) {
        Afirmar::igual(null, $a->leerCalculo($malo), $malo);
    }
    // Límites: 3 por ventana de 60 s; otra clave no se ve afectada; la ventana siguiente vuelve a empezar
    $t = 1_700_000_000;
    $res = [];
    for ($i = 0; $i < 5; $i++) {
        $res[] = $a->permitir('ip:1.2.3.4', 3, 60, $t);
    }
    Afirmar::igual([true, true, true, false, false], $res);
    Afirmar::verdadero($a->permitir('ip:5.6.7.8', 3, 60, $t));
    Afirmar::verdadero($a->permitir('ip:1.2.3.4', 3, 60, $t + 61));
    // La IP no queda guardada en claro
    $db = new PDO('sqlite:' . dirVarPrueba('almacen-x5') . '/calculos.sqlite');
    $claves = $db->query('SELECT clave FROM limites')->fetchAll(PDO::FETCH_COLUMN);
    Afirmar::verdadero($claves !== []);
    foreach ($claves as $clave) {
        Afirmar::falso(str_contains($clave, '1.2.3.4'));
        Afirmar::igual(64, strlen($clave));
    }
    // Purga: lo caducado se borra y lo reciente se queda
    $viejo = $a->guardarCalculo(['x' => 1], ['y' => 2]);
    $a->purgar(30, time() + 40 * 86400);
    Afirmar::igual(null, $a->leerCalculo($viejo), 'a los 40 días ya no está');
    $a2 = new Almacen(dirVarPrueba('almacen-x5'));
    $nuevo = $a2->guardarCalculo(['x' => 1], ['y' => 2]);
    $a2->purgar(30);
    Afirmar::verdadero($a2->leerCalculo($nuevo) !== null, 'lo reciente se conserva');
});

prueba('X6. Clave de seguridad: se crea sola, con permisos restrictivos, y se mantiene', function (): void {
    $dir = dirVarPrueba('almacen-x6') . '/nuevo/anidado';
    $a = new Almacen($dir);
    $k = $a->secreto();
    Afirmar::verdadero(strlen($k) >= 64);
    Afirmar::igual($k, (new Almacen($dir))->secreto(), 'otra instancia lee la misma');
    Afirmar::igual('0600', substr(sprintf('%o', fileperms($dir . '/secreto.key')), -4));
    Afirmar::igual('0750', substr(sprintf('%o', fileperms($dir)), -4), 'el directorio que crea el almacén no es público');
});

prueba('X7. Formato: euros, porcentajes y fechas a la española; todo escapado', function (): void {
    Afirmar::igual("1.234,56\u{a0}€", Vista::eur(1234.56));
    Afirmar::igual("0,00\u{a0}€", Vista::eur(0.0));
    Afirmar::igual('—', Vista::eur(null));
    Afirmar::igual("9,75\u{a0}%", Vista::pct(9.75));
    Afirmar::igual("4\u{a0}%", Vista::pct(4.0));
    Afirmar::igual('10/10/2026', Vista::fecha('2026-10-10'));
    Afirmar::igual('&lt;a href=&quot;x&quot;&gt;&#039;', Vista::e('<a href="x">\''));
    Afirmar::igual('', Vista::e(null));
    Afirmar::lanza(InvalidArgumentException::class, fn() => Vista::renderizar('../../etc/passwd'));
    Afirmar::lanza(InvalidArgumentException::class, fn() => Vista::renderizar('marco.php'));
});

prueba('X8. Un almacén de una versión anterior (contadores sin «tam») se actualiza solo', function (): void {
    $dir = dirVarPrueba('antiguo');
    $pdo = new PDO('sqlite:' . $dir . '/calculos.sqlite');
    $pdo->exec('CREATE TABLE limites (clave TEXT NOT NULL, ventana INTEGER NOT NULL, n INTEGER NOT NULL, PRIMARY KEY (clave, ventana))');
    $pdo->exec("INSERT INTO limites VALUES ('x', 1, 1)");
    unset($pdo);
    $a = new CalculadoraImportacion\Web\Almacen($dir);
    Afirmar::verdadero($a->permitir('ip', 2, 60, 1_000_000_020));
    Afirmar::verdadero($a->permitir('ip', 2, 60, 1_000_000_021));
    Afirmar::falso($a->permitir('ip', 2, 60, 1_000_000_022));
    // Ventanas de un segundo y de un minuto conviven sin borrarse entre sí
    Afirmar::verdadero($a->permitir('otra', 1, 1, 1_000_000_030));
    Afirmar::falso($a->permitir('ip', 2, 60, 1_000_000_031), 'el contador del minuto sigue vivo');
});
