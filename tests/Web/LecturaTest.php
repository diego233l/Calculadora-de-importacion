<?php

declare(strict_types=1);

use CalculadoraImportacion\Anuncio\Analizador;
use CalculadoraImportacion\Anuncio\DescargadorCurl;
use CalculadoraImportacion\Anuncio\Enlace;
use CalculadoraImportacion\Web\Almacen;
use CalculadoraImportacion\Web\LectorAnuncios;

function fixtureAnuncio(string $nombre): string
{
    $f = __DIR__ . '/../fixtures/anuncios/' . $nombre;
    return (string) file_get_contents(is_file($f . '.html') ? $f . '.html' : $f . '.txt');
}

/** Valor de un campo del formulario de datos tal como lo devuelve la página. */
function valorDeCampo(string $html, string $nombre): ?string
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $n = $xp->query("//form[@action='/calcular']//input[@name='$nombre'][@type='text']")->item(0);
    return $n instanceof DOMElement ? $n->getAttribute('value') : null;
}

function enlaceDePrueba(string $id = '345678901'): string
{
    return 'https://suchen.mobile.de/fahrzeuge/details.html?id=' . $id;
}

prueba('R1. Con el enlace, la web lee el anuncio, rellena el formulario y marca lo dudoso', function (): void {
    $d = new DescargadorFalso([DescargadorFalso::pagina(fixtureAnuncio('01-dl-privado'))]);
    $app = appPrueba(null, $d);
    [$cookies, $token] = sesionCsrf($app);
    $r = $app->manejar(peticionPost('/datos', ['csrf' => $token, 'enlace' => enlaceDePrueba(), 'municipio' => '28079', 'municipio_texto' => 'Madrid'], $cookies));
    Afirmar::igual(200, $r->estado);
    Afirmar::igual(1, $d->llamadas);
    Afirmar::igual(enlaceDePrueba(), $d->urls[0], 'se descarga la URL reconstruida, no lo que escribió el usuario');
    foreach (['marca' => 'BMW', 'modelo' => '320d', 'matriculacion' => '03/2019', 'km' => '105000', 'cc' => '1995', 'kw' => '140', 'cv' => '190',
        'co2' => '119', 'precio' => '16500', 'cp_vendedor' => '80331', 'enlace' => enlaceDePrueba()] as $campo => $esperado) {
        Afirmar::igual($esperado, valorDeCampo($r->cuerpo, $campo), $campo);
    }
    Afirmar::contiene('Hemos leído estos datos del anuncio', $r->cuerpo);
    Afirmar::contiene('<strong>Revisa:</strong>', $r->cuerpo);                // la versión sale del título: poca confianza
    Afirmar::contiene('selected', $r->cuerpo);
    Afirmar::verdadero(preg_match('/<option value="diesel" selected>/', $r->cuerpo) === 1, 'combustible elegido');
    Afirmar::verdadero(preg_match('/name="vendedor" value="particular" checked/', $r->cuerpo) === 1, 'vendedor elegido');
    sinInyeccion($r->cuerpo, 'datos leídos del anuncio');
    // El HTML descargado no se guarda en ninguna parte
    $dir = null;
    $ref = new ReflectionProperty($app, 'dirVar');
    $dir = $ref->getValue($app);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'sqlite') {
            Afirmar::falso(str_contains((string) file_get_contents($f->getPathname()), 'Händler-Suche'), 'el HTML del anuncio no se guarda');
        }
    }
});

prueba('R2. El segundo cálculo del mismo anuncio sale de la caché y no vuelve a llamar a mobile.de', function (): void {
    $d = new DescargadorFalso([DescargadorFalso::pagina(fixtureAnuncio('01-dl-privado'))]);
    $app = appPrueba(null, $d);
    [$cookies, $token] = sesionCsrf($app);
    $post = ['csrf' => $token, 'enlace' => enlaceDePrueba(), 'municipio' => '28079', 'municipio_texto' => 'Madrid'];
    $a = $app->manejar(peticionPost('/datos', $post, $cookies));
    $b = $app->manejar(peticionPost('/datos', $post, $cookies));
    Afirmar::igual(1, $d->llamadas);
    Afirmar::igual(valorDeCampo($a->cuerpo, 'km'), valorDeCampo($b->cuerpo, 'km'));
    Afirmar::igual('105000', valorDeCampo($b->cuerpo, 'km'));
});

prueba('R3. Si mobile.de bloquea la lectura se explica sin culpar al usuario y se ofrece pegar el texto', function (): void {
    foreach (['bloqueado' => 'bloquea las lecturas automáticas', 'no_existe' => 'no existe', 'tiempo' => 'tardado demasiado', 'red' => 'no hemos podido leer'] as $tipo => $frase) {
        $d = new DescargadorFalso([['ok' => false, 'estado' => $tipo === 'bloqueado' ? 403 : null, 'cuerpo' => null, 'tipo_error' => $tipo]]);
        $app = appPrueba(null, $d);
        [$cookies, $token] = sesionCsrf($app);
        $r = $app->manejar(peticionPost('/datos', ['csrf' => $token, 'enlace' => enlaceDePrueba(), 'municipio' => '28079', 'municipio_texto' => 'Madrid'], $cookies));
        Afirmar::igual(200, $r->estado, $tipo);
        Afirmar::contiene($frase, $r->cuerpo, $tipo);
        Afirmar::contiene('name="texto_anuncio"', $r->cuerpo, $tipo);
        Afirmar::igual('', valorDeCampo($r->cuerpo, 'km'), 'nada inventado');
        Afirmar::igual(enlaceDePrueba(), valorDeCampo($r->cuerpo, 'enlace'), 'el enlace se conserva');
        Afirmar::igual(1, $d->llamadas, 'una sola petición, sin reintentos');
    }
});

prueba('R4. Con el texto pegado se lee sin llamar a mobile.de y gana al enlace', function (): void {
    $d = new DescargadorFalso();
    $app = appPrueba(null, $d);
    [$cookies, $token] = sesionCsrf($app);
    $r = $app->manejar(peticionPost('/datos', ['csrf' => $token, 'enlace' => enlaceDePrueba(), 'municipio' => '28079', 'municipio_texto' => 'Madrid',
        'texto_anuncio' => fixtureAnuncio('31-texto-rotulo-y-valor-en-lineas')], $cookies));
    Afirmar::igual(200, $r->estado);
    Afirmar::igual(0, $d->llamadas);
    Afirmar::igual('105000', valorDeCampo($r->cuerpo, 'km'));
    Afirmar::igual('16500', valorDeCampo($r->cuerpo, 'precio'));
    Afirmar::contiene('del texto que has pegado', $r->cuerpo);
    // Sin enlace válido pero con texto: vale
    $r2 = $app->manejar(peticionPost('/datos', ['csrf' => $token, 'enlace' => 'http://malo.example/x', 'municipio' => '28079', 'municipio_texto' => 'Madrid',
        'texto_anuncio' => fixtureAnuncio('31-texto-rotulo-y-valor-en-lineas')], $cookies));
    Afirmar::igual(200, $r2->estado);
    Afirmar::igual('105000', valorDeCampo($r2->cuerpo, 'km'));
    // Texto que no es de un anuncio
    $r3 = $app->manejar(peticionPost('/datos', ['csrf' => $token, 'municipio' => '28079', 'municipio_texto' => 'Madrid', 'texto_anuncio' => "hola\nesto no es un anuncio"], $cookies));
    Afirmar::contiene('No hemos reconocido datos de un coche', $r3->cuerpo);
});

prueba('R5. Lo leído se puede calcular tal cual: del anuncio al desglose, con la fila del BOE y sin cilindros', function (): void {
    $app = appPrueba();
    [$cookies, $token] = sesionCsrf($app);
    $texto = fixtureAnuncio('41-texto-solo-anio-y-mes-escrito');           // VW Golf VII 2.0 TDI Highline, 03/2019, 95.000 km, 110 kW, 1.968 cm³
    $r = $app->manejar(peticionPost('/datos', ['csrf' => $token, 'municipio' => '28079', 'municipio_texto' => 'Madrid', 'texto_anuncio' => $texto], $cookies));
    $campos = [];
    foreach (['enlace', 'municipio', 'municipio_texto', 'marca', 'modelo', 'version', 'matriculacion', 'km', 'cc', 'cilindros', 'kw', 'cv', 'co2', 'precio', 'cp_vendedor'] as $c) {
        $campos[$c] = (string) valorDeCampo($r->cuerpo, $c);
    }
    Afirmar::igual('4', $campos['cilindros'], 'los cilindros salen de la fila del BOE');
    Afirmar::contiene('Orden del BOE', $r->cuerpo);
    preg_match('/name="municipio" value="(\d{5})"/', $r->cuerpo, $m);
    $campos['municipio'] = $m[1] ?? '28079';
    $post = ['csrf' => $token, 'combustible' => 'diesel', 'vendedor' => 'particular'] + $campos;
    $calc = $app->manejar(peticionPost('/calcular', $post, $cookies));
    Afirmar::igual(303, $calc->estado, 'se calcula sin más datos que los leídos');
    $id = substr($calc->cabeceras['Location'], 3);
    $res = $app->manejar(peticionGet('/c/' . $id))->cuerpo;
    Afirmar::contiene('Volkswagen', $res);
    Afirmar::contiene('Fila del BOE', $res);
    Afirmar::falso(str_contains($res, 'No calculable (falta el precio medio del BOE)'));
});

prueba('R6. Límites de lectura: 10 por minuto y por IP, 1 por segundo en total; la caché no cuenta', function (): void {
    $dir = dirVarPrueba('limites');
    $almacen = new Almacen($dir);
    $d = new DescargadorFalso([DescargadorFalso::pagina(fixtureAnuncio('01-dl-privado'))]);
    $t = 1_000_000_020;                                                    // empieza una ventana de 60 s
    $lector = new LectorAnuncios($almacen, $dir, Analizador::crear(__DIR__ . '/../../data'), $d, static function () use (&$t): int {
        return $t;
    });
    $enlace = static fn(int $n): array => ['id' => (string) (100000000 + $n), 'canonica' => enlaceDePrueba((string) (100000000 + $n))];
    // Dos lecturas en el mismo segundo: la segunda choca con el límite global
    Afirmar::igual('ok', $lector->leerEnlace('1.1.1.1', $enlace(1), '2026-10-10')['estado']);
    $x = $lector->leerEnlace('2.2.2.2', $enlace(2), '2026-10-10');
    Afirmar::igual('limite', $x['estado']);
    Afirmar::contiene('otro anuncio', (string) $x['mensaje']);
    Afirmar::igual(1, $d->llamadas, 'la limitada no llega a mobile.de');
    // Una caché no gasta cupo ni siquiera en el mismo segundo
    Afirmar::igual('ok', $lector->leerEnlace('3.3.3.3', $enlace(1), '2026-10-10')['estado']);
    Afirmar::igual(true, $lector->leerEnlace('3.3.3.3', $enlace(1), '2026-10-10')['desde_cache']);
    // Una por segundo desde la misma IP: las 10 primeras pasan (la 1.ª fue arriba) y la 11.ª no
    $resultados = [];
    for ($i = 3; $i <= 14; $i++) {
        $t++;
        $resultados[] = $lector->leerEnlace('1.1.1.1', $enlace($i), '2026-10-10')['estado'];
    }
    Afirmar::igual(array_fill(0, 8, 'ok'), array_slice($resultados, 0, 8), 'la 2.ª a la 9.ª de la IP');
    Afirmar::igual('limite', $resultados[9], 'la 11.ª en un minuto');
    // Otro minuto: vuelve a funcionar
    $t += 120;
    Afirmar::igual('ok', $lector->leerEnlace('1.1.1.1', $enlace(99), '2026-10-10')['estado']);
    // La caché caduca a las 24 h
    $t += 90000;
    $antes = $d->llamadas;
    $lector->leerEnlace('1.1.1.1', $enlace(1), '2026-10-11');
    Afirmar::igual($antes + 1, $d->llamadas);
});

prueba('R7. Una página descargada que no es un anuncio se declara ilegible y no se guarda en la caché', function (): void {
    $dir = dirVarPrueba('ilegible');
    $d = new DescargadorFalso([DescargadorFalso::pagina('<html><body><h1>Noticias</h1><p>Nada de coches aquí.</p></body></html>')]);
    $lector = new LectorAnuncios(new Almacen($dir), $dir, Analizador::crear(__DIR__ . '/../../data'), $d);
    $r = $lector->leerEnlace('9.9.9.9', ['id' => '345678901', 'canonica' => enlaceDePrueba()], '2026-10-10');
    Afirmar::igual('ilegible', $r['estado']);
    Afirmar::contiene('Pega el texto del anuncio', (string) $r['mensaje']);
    Afirmar::igual(null, (new Almacen($dir))->leerAnuncio('345678901'));
    Afirmar::contiene('anuncio_ilegible', (string) file_get_contents($dir . '/registro.log'));
    Afirmar::falso(str_contains((string) file_get_contents($dir . '/registro.log'), '9.9.9.9'), 'el registro no guarda la IP');
});

prueba('R8. El descargador solo habla con hosts de mobile.de por https y detecta páginas anti-bot', function (): void {
    $c = new DescargadorCurl();
    foreach (['http://suchen.mobile.de/x', 'https://evil.example/x', 'https://suchen.mobile.de.evil.example/x', 'https://user:pw@suchen.mobile.de/x',
        'https://suchen.mobile.de:8443/x', 'ftp://suchen.mobile.de/x', 'file:///etc/passwd', 'https://127.0.0.1/x'] as $malo) {
        $r = $c->obtener($malo);
        Afirmar::falso($r['ok'], $malo);
        Afirmar::igual('redireccion', $r['tipo_error'], $malo);
    }
    Afirmar::verdadero(Enlace::hostPermitido('https://www.mobile.de/fahrzeuge/details.html?id=1'));
    Afirmar::falso(Enlace::hostPermitido("https://www.mobile.de/\r\nHost: evil"));
    Afirmar::verdadero(DescargadorCurl::esDesafio('<html><title>Zugriff verweigert / Access denied</title>'));
    Afirmar::verdadero(DescargadorCurl::esDesafio('<h1>Pardon Our Interruption</h1>'));
    Afirmar::falso(DescargadorCurl::esDesafio('<html><h1>BMW 320d</h1><p>Kilometerstand 105.000 km</p></html>'));
    Afirmar::contiene('CalculadoraImportacion/', DescargadorCurl::USER_AGENT);
    Afirmar::igual(10, DescargadorCurl::TIEMPO_MAX);
    Afirmar::igual(3 * 1024 * 1024, DescargadorCurl::BYTES_MAX);
});
