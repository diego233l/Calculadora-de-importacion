<?php

declare(strict_types=1);

use CalculadoraImportacion\Web\Csrf;
use CalculadoraImportacion\Web\Peticion;
use CalculadoraImportacion\Web\Respuesta;

/** Analiza el HTML como lo haría el navegador: ni elementos ni atributos que no haya puesto la plantilla. */
function sinInyeccion(string $html, string $contexto): void
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    Afirmar::igual(0, $doc->getElementsByTagName('img')->length, "$contexto: aparece un <img> inyectado");
    Afirmar::igual(0, $doc->getElementsByTagName('iframe')->length, "$contexto: aparece un <iframe>");
    $scripts = $doc->getElementsByTagName('script');
    Afirmar::igual(1, $scripts->length, "$contexto: solo debe haber el script de la web");
    Afirmar::igual('', trim($scripts->item(0)->textContent), "$contexto: el script no puede llevar código en línea");
    foreach ($doc->getElementsByTagName('*') as $el) {
        foreach ($el->attributes as $atributo) {
            Afirmar::falso(str_starts_with(strtolower($atributo->name), 'on'), "$contexto: atributo {$atributo->name} en <{$el->nodeName}>");
        }
    }
}

prueba('W1. La portada responde con las cabeceras de seguridad, la cookie técnica y el aviso visible', function (): void {
    $r = appPrueba()->manejar(peticionGet('/'));
    Afirmar::igual(200, $r->estado);
    Afirmar::contiene("default-src 'none'", $r->cabeceras['Content-Security-Policy']);
    Afirmar::contiene("script-src 'self'", $r->cabeceras['Content-Security-Policy']);
    Afirmar::falso(str_contains($r->cabeceras['Content-Security-Policy'], 'unsafe-inline'));
    Afirmar::contiene("frame-ancestors 'none'", $r->cabeceras['Content-Security-Policy']);
    Afirmar::igual('nosniff', $r->cabeceras['X-Content-Type-Options']);
    Afirmar::igual('DENY', $r->cabeceras['X-Frame-Options']);
    Afirmar::igual('no-referrer', $r->cabeceras['Referrer-Policy']);
    Afirmar::contiene('Estimación orientativa; confirma con tu gestoría o Hacienda.', $r->cuerpo);
    Afirmar::contiene('<html lang="es">', $r->cuerpo);
    Afirmar::contiene('/aviso-legal', $r->cuerpo);
    Afirmar::contiene('/privacidad', $r->cuerpo);
    // Cookie técnica: HttpOnly, SameSite=Lax; Secure solo bajo https
    $c = $r->cookies[0];
    Afirmar::igual(Csrf::COOKIE, $c['nombre']);
    Afirmar::verdadero($c['opciones']['httponly']);
    Afirmar::igual('Lax', $c['opciones']['samesite']);
    Afirmar::falso($c['opciones']['secure']);
    $https = appPrueba()->manejar(new Peticion('GET', '/', [], [], [], '10.0.0.1', true));
    Afirmar::verdadero($https->cookies[0]['opciones']['secure']);
    // Sin ningún recurso de terceros
    Afirmar::igual(0, preg_match('#(src|href)="https?://(?!(www\.geonames\.org|www\.boe\.es))#', $r->cuerpo));
    // Ningún estilo ni manejador en línea (la CSP los bloquearía)
    Afirmar::igual(0, preg_match('/\sstyle="/', $r->cuerpo));
    Afirmar::igual(0, preg_match('/\son[a-z]+="/', $r->cuerpo));
});

prueba('W2. Rutas: 404, 405, cuerpo demasiado grande y HEAD', function (): void {
    $app = appPrueba();
    Afirmar::igual(404, $app->manejar(peticionGet('/no-existe'))->estado);
    Afirmar::igual(404, $app->manejar(peticionGet('/c/zzzzzzzzzzzz'))->estado);
    Afirmar::igual(404, $app->manejar(peticionGet('/c/000000000000'))->estado, 'cálculo que no existe');
    Afirmar::igual(404, $app->manejar(peticionGet('/c/../../etc/passwd'))->estado);
    Afirmar::igual(404, $app->manejar(peticionGet('/' . str_repeat('a', 300)))->estado);
    Afirmar::igual(404, $app->manejar(peticionGet('/guias/no-existe'))->estado);
    Afirmar::igual(405, $app->manejar(peticionPost('/', []))->estado);
    Afirmar::igual(405, $app->manejar(peticionGet('/calcular'))->estado);
    Afirmar::igual(413, $app->manejar(new Peticion('POST', '/calcular', [], [], [], '10.0.0.1', false, 999999))->estado);
    Afirmar::igual(200, $app->manejar(peticionGet('/datos'))->estado, 'rellenar a mano, sin anuncio');
    $head = $app->manejar(new Peticion('HEAD', '/'));
    Afirmar::igual(200, $head->estado);
    // Las páginas de error tampoco enseñan rutas internas
    Afirmar::falso(str_contains($app->manejar(peticionGet('/no-existe'))->cuerpo, '/home/'));
});

prueba('W3. CSRF: sin token, con token falso o con la cookie de otro navegador, se rechaza', function (): void {
    $app = appPrueba();
    [$cookies, $token] = sesionCsrf($app);
    $datos = ['enlace' => 'https://suchen.mobile.de/fahrzeuge/details.html?id=345678901'];
    Afirmar::igual(403, $app->manejar(peticionPost('/datos', $datos, $cookies))->estado, 'sin token');
    Afirmar::igual(403, $app->manejar(peticionPost('/datos', ['csrf' => str_repeat('a', 64)] + $datos, $cookies))->estado, 'token falso');
    Afirmar::igual(403, $app->manejar(peticionPost('/datos', ['csrf' => $token] + $datos, []))->estado, 'sin cookie');
    $otra = [Csrf::COOKIE => bin2hex(random_bytes(16))];
    Afirmar::igual(403, $app->manejar(peticionPost('/datos', ['csrf' => $token] + $datos, $otra))->estado, 'cookie de otro navegador');
    Afirmar::igual(403, $app->manejar(peticionPost('/calcular', ['csrf' => $token, 'km' => '1'], []))->estado);
    Afirmar::igual(200, $app->manejar(peticionPost('/datos', ['csrf' => $token] + $datos, $cookies))->estado, 'con token y cookie válidos');
    // Un token firmado con la clave de otra instalación no vale
    $otraApp = appPrueba();
    Afirmar::igual(403, $otraApp->manejar(peticionPost('/datos', ['csrf' => $token] + $datos, $cookies))->estado);
    // El mensaje de error es útil y no filtra nada
    $r = $app->manejar(peticionPost('/datos', $datos, $cookies));
    Afirmar::contiene('caducado', $r->cuerpo);
});

prueba('W4. Primer paso: solo el enlace (o el texto) del anuncio; solo mobile.de; el municipio se elige en el paso siguiente', function (): void {
    $app = appPrueba();
    [$cookies, $token] = sesionCsrf($app);
    $envio = fn(array $d) => $app->manejar(peticionPost('/datos', ['csrf' => $token] + $d, $cookies));

    $r = $envio(['enlace' => 'http://evil.com/x']);
    Afirmar::igual(422, $r->estado);
    Afirmar::contiene('mobile.de', $r->cuerpo);
    Afirmar::contiene('aria-invalid="true"', $r->cuerpo);
    Afirmar::contiene('role="alert"', $r->cuerpo);

    $r = $envio(['enlace' => 'https://www.mobile.de.evil.com/fahrzeuge/details.html?id=345678901']);
    Afirmar::igual(422, $r->estado);
    Afirmar::contiene('mobile.de', $r->cuerpo);

    $r = $envio(['enlace' => '', 'texto_anuncio' => '']);
    Afirmar::igual(422, $r->estado);
    Afirmar::contiene('Pega el enlace del anuncio', $r->cuerpo);

    // Con el enlace bien, se pasa al formulario de datos (sin leer el anuncio, mobile.de bloquea al descargador falso)
    $r = $envio(['enlace' => 'https://suchen.mobile.de/fahrzeuge/details.html?id=345678901']);
    Afirmar::igual(200, $r->estado);
    Afirmar::contiene('details.html?id=345678901', $r->cuerpo);
    Afirmar::contiene('¿Dónde vas a matricularlo?', $r->cuerpo);

    // Un municipio que ya venía elegido se conserva
    $r = $envio(['enlace' => 'https://suchen.mobile.de/fahrzeuge/details.html?id=345678901', 'municipio_texto' => 'Madrid']);
    Afirmar::igual(200, $r->estado);
    Afirmar::contiene('value="28079"', $r->cuerpo);
});

prueba('W4b. Municipios de destino: solo comunidades soportadas, con sugerencias si es ambiguo', function (): void {
    $app = appPrueba();
    [$cookies, $token] = sesionCsrf($app);
    $envio = fn(array $d) => $app->manejar(peticionPost('/calcular', ['csrf' => $token] + datosCoche($d), $cookies));

    foreach (['Las Palmas de Gran Canaria' => 'Canarias', 'Pamplona' => 'Navarra', 'Bilbao' => 'País Vasco', 'Ceuta' => 'Ceuta'] as $texto => $region) {
        $r = $envio(['municipio' => '', 'municipio_texto' => $texto]);
        Afirmar::igual(422, $r->estado, $texto);
        Afirmar::contiene('De momento no calculamos importaciones para', html_entity_decode($r->cuerpo), $texto);
    }

    $r = $envio(['municipio' => '', 'municipio_texto' => '']);
    Afirmar::igual(422, $r->estado);
    Afirmar::contiene('Elige el municipio', $r->cuerpo);

    $r = $envio(['municipio' => '', 'municipio_texto' => 'Sala']);
    Afirmar::igual(422, $r->estado);
    Afirmar::contiene('Salamanca', $r->cuerpo, 'sugiere municipios parecidos');
});

prueba('W5. Camino feliz: se calcula, se guarda y el resultado se puede abrir con su enlace', function (): void {
    [$app, $id, $cookies, $token, $r] = calcularCoche();
    Afirmar::igual(303, $r->estado);
    Afirmar::verdadero(preg_match('/^[a-f0-9]{12}$/', $id) === 1, 'redirige a /c/<12 hex>');
    $p = $app->manejar(peticionGet('/c/' . $id));
    Afirmar::igual(200, $p->estado);
    Afirmar::contiene('Volkswagen Golf 2.0 TDI', $p->cuerpo);
    Afirmar::contiene('Coste total estimado', $p->cuerpo);
    Afirmar::contiene('20.571,13', html_entity_decode($p->cuerpo, ENT_QUOTES, 'UTF-8'));
    Afirmar::contiene('Impuesto de matriculación (IEDMT)', $p->cuerpo);
    Afirmar::contiene('Estimación orientativa; confirma con tu gestoría o Hacienda.', $p->cuerpo);
    Afirmar::contiene('noindex', $p->cuerpo, 'los cálculos compartidos no se indexan');
    Afirmar::contiene('rel="noopener noreferrer"', $p->cuerpo);
    Afirmar::contiene('private', $p->cabeceras['Cache-Control']);
    // Cada partida enlaza con su fuente
    Afirmar::contiene('https://www.boe.es/', $p->cuerpo);
    // El enlace compartible es absoluto cuando hay host
    $ab = $app->manejar(new Peticion('GET', '/c/' . $id, [], [], [], '10.0.0.1', true, 0, 'calculadora.example'));
    Afirmar::contiene('https://calculadora.example/c/' . $id, $ab->cuerpo);
    // La cabecera Host manipulada no se refleja
    $mal = $app->manejar(new Peticion('GET', '/c/' . $id, [], [], [], '10.0.0.1', false, 0, Peticion::hostSeguro('evil.com"><script>')));
    Afirmar::falso(str_contains($mal->cuerpo, 'evil.com'));
});

prueba('W6. Datos incompletos o absurdos: errores por campo, sin guardar nada', function (): void {
    $app = appPrueba();
    [$cookies, $token] = sesionCsrf($app);
    $r = $app->manejar(peticionPost('/calcular', ['csrf' => $token, 'municipio' => '28079', 'municipio_texto' => 'Madrid'], $cookies));
    Afirmar::igual(422, $r->estado);
    foreach (['matriculacion', 'km', 'combustible', 'precio', 'vendedor', 'cp_vendedor'] as $campo) {
        Afirmar::contiene('id="e-' . $campo . '"', $r->cuerpo, $campo);
    }
    Afirmar::contiene('href="#f-km"', $r->cuerpo, 'el resumen enlaza con el campo');

    $casos = [
        ['km' => '-5'], ['km' => 'mucho'], ['km' => '3.000.000'], ['matriculacion' => '13/2020'], ['matriculacion' => '03/2099'],
        ['matriculacion' => 'ayer'], ['precio' => '0'], ['precio' => 'gratis'], ['cp_vendedor' => '1234'], ['cp_vendedor' => 'ABCDE'],
        ['combustible' => 'agua'], ['vendedor' => 'robot'], ['cilindros' => '40'], ['co2' => '-1'], ['boe_precio' => '3'],
    ];
    foreach ($casos as $malo) {
        $r = $app->manejar(peticionPost('/calcular', ['csrf' => $token] + datosCoche($malo), $cookies));
        Afirmar::igual(422, $r->estado, json_encode($malo));
        Afirmar::igual(null, $r->cabeceras['Location'] ?? null, json_encode($malo));
    }
    // Un eléctrico no necesita cilindrada ni CO2, pero sí kW
    $e = datosCoche(['combustible' => 'electrico', 'cc' => '', 'cilindros' => '', 'co2' => '', 'cv' => '', 'kw' => '100']);
    Afirmar::igual(303, $app->manejar(peticionPost('/calcular', ['csrf' => $token] + $e, $cookies))->estado);
    $sinKw = datosCoche(['combustible' => 'electrico', 'cc' => '', 'cilindros' => '', 'co2' => '', 'cv' => '', 'kw' => '']);
    $r = $app->manejar(peticionPost('/calcular', ['csrf' => $token] + $sinKw, $cookies));
    Afirmar::igual(422, $r->estado);
    Afirmar::contiene('kW', $r->cuerpo);
});

prueba('W7. Todo lo escrito por el usuario sale escapado (XSS) y los campos de otro tipo se ignoran', function (): void {
    $xss = '<script>alert(1)</script>"><img src=x onerror=alert(2)>\'';
    [$app, $id, $cookies, $token] = calcularCoche(['marca' => $xss, 'modelo' => $xss, 'version' => $xss]);
    Afirmar::verdadero($id !== '');
    foreach (['/c/' . $id, '/c/' . $id . '/editar'] as $ruta) {
        $html = $app->manejar(peticionGet($ruta))->cuerpo;
        Afirmar::falso(str_contains($html, '<script>alert(1)'), "$ruta: script sin escapar");
        sinInyeccion($html, $ruta);
        Afirmar::contiene('&lt;script&gt;', $html, $ruta);
        Afirmar::contiene('&lt;img src=x onerror=alert(2)&gt;', $html, $ruta . ': debe verse como texto inerte');
    }
    // En un formulario con errores, lo escrito vuelve escapado
    $r = $app->manejar(peticionPost('/calcular', ['csrf' => $token] + datosCoche(['marca' => $xss, 'km' => 'x']), $cookies));
    Afirmar::igual(422, $r->estado);
    Afirmar::falso(str_contains($r->cuerpo, '<script>alert(1)'));
    Afirmar::contiene('&lt;script&gt;', $r->cuerpo);
    // Un array donde se espera texto no rompe nada
    $r = $app->manejar(peticionPost('/calcular', ['csrf' => $token] + datosCoche(['km' => ['1', '2'], 'marca' => ['x']]), $cookies));
    Afirmar::igual(422, $r->estado);
    // El JSON del botón del historial va dentro de un atributo, escapado
    $p = $app->manejar(peticionGet('/c/' . $id))->cuerpo;
    Afirmar::falso(str_contains($p, 'data-guardar-historial="{"'), 'comillas sin escapar en el atributo');
    // Mensajes de error con el municipio escrito
    $r = $app->manejar(peticionPost('/datos', ['csrf' => $token, 'municipio_texto' => '<b>x</b>', 'enlace' => '<i>y</i>'], $cookies));
    Afirmar::falso(str_contains($r->cuerpo, '<b>x</b>'));
    Afirmar::falso(str_contains($r->cuerpo, '<i>y</i>'));
});

prueba('W8. Editar y ajustar costes: se crea un cálculo nuevo y el original no cambia', function (): void {
    [$app, $id, $cookies, $token] = calcularCoche();
    $ed = $app->manejar(peticionGet('/c/' . $id . '/editar'));
    Afirmar::igual(200, $ed->estado);
    Afirmar::contiene('value="Volkswagen"', $ed->cuerpo);
    Afirmar::contiene('value="03/2021"', $ed->cuerpo);
    Afirmar::contiene('value="80000"', $ed->cuerpo);
    Afirmar::contiene('value="38000"', $ed->cuerpo);
    Afirmar::contiene('value="Madrid"', $ed->cuerpo);
    // Gestoría 200 -> 350: el total sube 150
    [$c2, $t2] = [[Csrf::COOKIE => $ed->cookies[0]['valor'] ?? $cookies[Csrf::COOKIE]], ''];
    preg_match('/name="csrf" value="([a-f0-9]{64})"/', $ed->cuerpo, $m);
    $r = $app->manejar(peticionPost('/c/' . $id . '/costes', ['csrf' => $m[1], 'cf_gestoria' => '350', 't_modo' => 'camion'], $c2 + $cookies));
    Afirmar::igual(303, $r->estado);
    $nuevo = substr($r->cabeceras['Location'], 3);
    Afirmar::verdadero($nuevo !== $id && preg_match('/^[a-f0-9]{12}$/', $nuevo) === 1);
    $html = html_entity_decode($app->manejar(peticionGet('/c/' . $nuevo))->cuerpo, ENT_QUOTES, 'UTF-8');
    Afirmar::contiene('350,00', $html);
    Afirmar::contiene('Transporte en camión portavehículos', $html);
    $orig = html_entity_decode($app->manejar(peticionGet('/c/' . $id))->cuerpo, ENT_QUOTES, 'UTF-8');
    Afirmar::contiene('20.571,13', $orig, 'el original sigue igual');
    // Importes absurdos o texto
    foreach ([['cf_gestoria' => '-3'], ['cf_placas' => 'mucho'], ['t_modo' => 'avion'], ['t_peajes' => '999999999'], ['t_velocidad' => '0']] as $malo) {
        $r = $app->manejar(peticionPost('/c/' . $id . '/costes', ['csrf' => $m[1]] + $malo, $c2 + $cookies));
        Afirmar::igual(422, $r->estado, json_encode($malo));
        Afirmar::contiene('role="alert"', $r->cuerpo);
    }
    Afirmar::igual(403, $app->manejar(peticionPost('/c/' . $id . '/costes', ['cf_gestoria' => '1'], $cookies))->estado, 'sin token');
    Afirmar::igual(404, $app->manejar(peticionPost('/c/000000000000/costes', ['csrf' => $m[1]], $c2 + $cookies))->estado);
});

prueba('W9. Límite de cálculos por IP y por minuto', function (): void {
    $app = appPrueba();
    [$cookies, $token] = sesionCsrf($app);
    $estados = [];
    for ($i = 0; $i < 22; $i++) {
        $estados[] = $app->manejar(peticionPost('/calcular', ['csrf' => $token] + datosCoche(), $cookies, '203.0.113.7'))->estado;
    }
    Afirmar::igual(303, $estados[0]);
    Afirmar::igual(303, $estados[19], 'el límite son 20 por minuto');
    Afirmar::igual(429, $estados[20]);
    Afirmar::igual(429, $estados[21]);
    // Otra IP no se ve afectada
    Afirmar::igual(303, $app->manejar(peticionPost('/calcular', ['csrf' => $token] + datosCoche(), $cookies, '203.0.113.8'))->estado);
    $r = $app->manejar(peticionPost('/calcular', ['csrf' => $token] + datosCoche(), $cookies, '203.0.113.7'));
    Afirmar::igual('60', $r->cabeceras['Retry-After']);
});

prueba('W10. Comparador: hasta 3 cálculos por lista de identificadores; los inexistentes se avisan', function (): void {
    [$app, $a] = calcularCoche(['modelo' => 'Golf']);
    [, $b] = calcularCoche(['modelo' => 'Passat', 'precio' => '20.000'], $app);
    [, $c] = calcularCoche(['modelo' => 'Polo', 'precio' => '12.000'], $app);
    [, $d] = calcularCoche(['modelo' => 'Tiguan'], $app);
    $r = $app->manejar(peticionGet('/comparar', ['c' => "$a,$b"]));
    Afirmar::igual(200, $r->estado);
    foreach (['Golf', 'Passat'] as $m) {
        Afirmar::contiene($m, $r->cuerpo);
    }
    Afirmar::contiene('el más barato', $r->cuerpo);
    $r = $app->manejar(peticionGet('/comparar', ['c' => "$a,$b,$c,$d"]));
    Afirmar::falso(str_contains($r->cuerpo, 'Tiguan'), 'solo 3');
    Afirmar::contiene('Polo', $r->cuerpo);
    $r = $app->manejar(peticionGet('/comparar', ['c' => "$a,000000000000,zzz"]));
    Afirmar::contiene('no existe o ha caducado', $r->cuerpo);
    $r = $app->manejar(peticionGet('/comparar', ['c' => ['x' => 'y']]));
    Afirmar::igual(200, $r->estado);
    Afirmar::contiene('No hay cálculos que comparar', $app->manejar(peticionGet('/comparar'))->cuerpo);
    Afirmar::contiene('noindex', $app->manejar(peticionGet('/comparar'))->cuerpo);
});

prueba('W11. API de municipios: ordenada por tamaño, sin territorios no soportados y con límites', function (): void {
    $app = appPrueba();
    $buscar = function (string $q) use ($app): array {
        $r = $app->manejar(peticionGet('/api/municipios', ['q' => $q]));
        Afirmar::igual(200, $r->estado);
        Afirmar::contiene('application/json', $r->cabeceras['Content-Type']);
        return json_decode($r->cuerpo, true)['resultados'];
    };
    Afirmar::igual('Madrid', $buscar('mad')[0]['nombre']);
    Afirmar::igual('Salamanca', $buscar('sala')[0]['nombre']);
    Afirmar::igual('Madrid', $buscar('MÁDRID')[0]['nombre'], 'sin distinguir mayúsculas ni acentos');
    Afirmar::igual('Córdoba', $buscar('cordoba')[0]['nombre']);
    Afirmar::igual([], $buscar('m'), 'consultas de una letra no devuelven nada');
    Afirmar::igual([], $buscar(''));
    Afirmar::igual([], $buscar('las palmas'), 'Canarias no está soportada');
    Afirmar::igual([], $buscar('pamplona'));
    Afirmar::igual([], $buscar('bilbao'));
    Afirmar::verdadero(count($buscar('san')) <= 8);
    Afirmar::igual([], $buscar(str_repeat('x', 500)));
    $fila = $buscar('salamanca')[0];
    Afirmar::igual(['ine', 'nombre', 'provincia'], array_keys($fila));
    Afirmar::igual('37274', $fila['ine']);
});

prueba('W12. Si no se puede escribir en el directorio de datos: error 503 genérico, sin rutas internas', function (): void {
    $dir = dirVarPrueba('fichero');
    $fichero = $dir . '/no-es-un-directorio';
    file_put_contents($fichero, 'x');
    $app = new \CalculadoraImportacion\Web\App($fichero . '/dentro', static fn(): string => '2026-10-10');
    $r = $app->manejar(peticionGet('/'));
    Afirmar::igual(503, $r->estado);
    Afirmar::falso(str_contains($r->cuerpo, $fichero));
    Afirmar::falso(str_contains($r->cuerpo, '/tmp'));
    Afirmar::contiene('Servicio no disponible', $r->cuerpo);
    // Las páginas que no necesitan almacén siguen funcionando
    Afirmar::igual(200, $app->manejar(peticionGet('/guias'))->estado);
    Afirmar::igual(200, $app->manejar(peticionGet('/privacidad'))->estado);
});

prueba('W13. Páginas de contenido: guías, aviso legal y privacidad', function (): void {
    $app = appPrueba();
    foreach (['/guias', '/guias/importar-coche-de-alemania', '/guias/impuesto-de-matriculacion', '/guias/itp', '/aviso-legal', '/privacidad', '/historial'] as $ruta) {
        $r = $app->manejar(peticionGet($ruta));
        Afirmar::igual(200, $r->estado, $ruta);
        Afirmar::contiene('<h1', $r->cuerpo, $ruta);
        Afirmar::contiene('<title>', $r->cuerpo, $ruta);
        Afirmar::contiene('Estimación orientativa; confirma con tu gestoría o Hacienda.', $r->cuerpo, $ruta);
    }
    $legal = $app->manejar(peticionGet('/aviso-legal'))->cuerpo;
    Afirmar::contiene('Pendiente de completar', $legal, 'mientras data/sitio.json esté vacío, la página lo dice');
    Afirmar::contiene('Estimación orientativa', $legal);
    $priv = $app->manejar(peticionGet('/privacidad'))->cuerpo;
    Afirmar::contiene('ci_csrf', $priv);
    Afirmar::contiene('365 días', $priv);
    // La guía del impuesto de matriculación repite la cifra del ejemplo, que debe coincidir con el motor
    $g = $app->manejar(peticionGet('/guias/impuesto-de-matriculacion'))->cuerpo;
    foreach (['14.820,00', '11.334,61', '1.105,12'] as $cifra) {
        Afirmar::contiene($cifra, $g);
    }
});

prueba('W14. La redirección interna solo acepta rutas propias', function (): void {
    Afirmar::igual('/c/abc', Respuesta::redirigir('/c/abc')->cabeceras['Location']);
    foreach (['//evil.com', 'https://evil.com', 'javascript:alert(1)', '', '/\\evil.com'] as $malo) {
        $loc = Respuesta::redirigir($malo)->cabeceras['Location'];
        Afirmar::verdadero($loc === '/' || str_starts_with($loc, '/\\'), $malo);
    }
    Afirmar::igual('', Peticion::hostSeguro('evil.com"><script>'));
    Afirmar::igual('calculadora.example:8082', Peticion::hostSeguro('Calculadora.Example:8082'));
    Afirmar::igual('', Peticion::hostSeguro("a.com\r\nX-Evil: 1"));
});

prueba('W15. Sin precio del BOE se busca la fila sola, se enseña con sus alternativas y se avisa', function (): void {
    [$app, $id, $cookies, , $r] = calcularCoche(['boe_precio' => '', 'marca' => 'Volkswagen', 'modelo' => 'Golf VII', 'version' => '2.0 TDI Highline', 'matriculacion' => '03/2019']);
    Afirmar::igual(303, $r->estado, 'se calcula sin escribir el precio del BOE');
    $p = $app->manejar(peticionGet('/c/' . $id));
    Afirmar::igual(200, $p->estado);
    Afirmar::contiene('Modelo identificado (base BOE)', $p->cuerpo);
    Afirmar::contiene('GOLF VII 2.0 TDI', $p->cuerpo);
    Afirmar::contiene('Valor de referencia', $p->cuerpo);
    Afirmar::contiene('Caballos fiscales', $p->cuerpo);
    Afirmar::contiene('insignia--auto">AUTO<', $p->cuerpo, 'elegida por nosotros');
    Afirmar::contiene('Otros modelos posibles', $p->cuerpo);
    Afirmar::contiene('name="fila"', $p->cuerpo);
    Afirmar::contiene('/c/' . $id . '/boe', $p->cuerpo);
    Afirmar::contiene('de coincidencia', $p->cuerpo);
    Afirmar::falso(str_contains($p->cuerpo, 'No calculable (falta el precio medio del BOE)'), 'ya hay valor venal');
    sinInyeccion($p->cuerpo, 'resultado con fila del BOE');
    Afirmar::igual(0, preg_match('/\sstyle="/', $p->cuerpo));
});

prueba('W16. Elegir otra fila del BOE crea un cálculo nuevo con el precio de esa fila; una fila de otra marca se rechaza', function (): void {
    [$app, $id, $cookies, $token] = calcularCoche(['boe_precio' => '', 'marca' => 'Volkswagen', 'modelo' => 'Golf VII', 'version' => '2.0 TDI', 'matriculacion' => '03/2019']);
    $original = $app->manejar(peticionGet('/c/' . $id))->cuerpo;
    preg_match_all('/<button class="alternativa( alternativa--uso)?" type="submit" name="fila" value="(\d+)"/', $original, $m, PREG_SET_ORDER);
    Afirmar::verdadero(count($m) >= 2, 'al menos dos filas propuestas');
    $enUso = null;
    $otra = null;
    foreach ($m as $f) {
        $f[1] !== '' ? $enUso = (int) $f[2] : $otra ??= (int) $f[2];
    }
    Afirmar::verdadero($enUso !== null && $otra !== null && $enUso !== $otra);
    $r = $app->manejar(peticionPost('/c/' . $id . '/boe', ['csrf' => $token, 'fila' => (string) $otra], $cookies));
    Afirmar::igual(303, $r->estado);
    preg_match('#^/c/([a-f0-9]{12})$#', $r->cabeceras['Location'], $n);
    Afirmar::verdadero($n[1] !== $id, 'cálculo nuevo; el original no cambia');
    $nuevo = $app->manejar(peticionGet('/c/' . $n[1]))->cuerpo;
    Afirmar::contiene('insignia--manual">ELEGIDO<', $nuevo);
    Afirmar::contiene('alternativa--uso" type="submit" name="fila" value="' . $otra . '"', $nuevo, 'la fila elegida queda marcada');
    Afirmar::contiene('insignia--auto">AUTO<', $app->manejar(peticionGet('/c/' . $id))->cuerpo);

    // Fila de otra marca (la primera de ABARTH es la 1) o inexistente: 422 y no se guarda nada
    foreach (['1', '99999999', 'abc', '', '-3'] as $mala) {
        $x = $app->manejar(peticionPost('/c/' . $id . '/boe', ['csrf' => $token, 'fila' => $mala], $cookies));
        Afirmar::igual(422, $x->estado, "fila «{$mala}»");
    }
    // Sin token de seguridad
    Afirmar::igual(403, $app->manejar(peticionPost('/c/' . $id . '/boe', ['fila' => (string) $otra], $cookies))->estado);
    Afirmar::igual(404, $app->manejar(peticionPost('/c/aaaaaaaaaaaa/boe', ['csrf' => $token, 'fila' => (string) $otra], $cookies))->estado);
});

prueba('W17. Una marca que no está en la Orden o sin modelo avisa y deja el valor venal como no calculable', function (): void {
    [$app, $id, , , $r] = calcularCoche(['boe_precio' => '', 'marca' => 'Ora', 'modelo' => 'Funky Cat', 'version' => '', 'combustible' => 'electrico', 'cc' => '', 'cilindros' => '', 'kw' => '126', 'cv' => '']);
    Afirmar::igual(303, $r->estado);
    $p = $app->manejar(peticionGet('/c/' . $id))->cuerpo;
    Afirmar::contiene('No hemos encontrado tu coche en la Orden del BOE', $p);
    Afirmar::contiene('/c/' . $id . '/editar', $p);
    [$app2, $id2] = calcularCoche(['boe_precio' => '', 'marca' => '', 'modelo' => '']);
    Afirmar::contiene('No has indicado marca y modelo', $app2->manejar(peticionGet('/c/' . $id2))->cuerpo);
});

prueba('W18. El precio del BOE escrito a mano manda sobre la búsqueda y se conserva al editar', function (): void {
    [$app, $id] = calcularCoche(['boe_precio' => '30.000']);
    $p = $app->manejar(peticionGet('/c/' . $id))->cuerpo;
    Afirmar::contiene('Precio medio del BOE (indicado por ti)', $p);
    Afirmar::contiene('insignia--manual">MANUAL<', $p);
    Afirmar::contiene('30.000', $p);
    $editar = $app->manejar(peticionGet('/c/' . $id . '/editar'))->cuerpo;
    Afirmar::contiene('name="boe_precio" value="30000"', $editar);
    // Una fila elegida por el sistema NO se copia al formulario de edición (se vuelve a buscar al recalcular)
    [$app2, $id2] = calcularCoche(['boe_precio' => '', 'marca' => 'Volkswagen', 'modelo' => 'Golf VII', 'version' => '2.0 TDI', 'matriculacion' => '03/2019']);
    Afirmar::contiene('name="boe_precio" value=""', $app2->manejar(peticionGet('/c/' . $id2 . '/editar'))->cuerpo);
});

prueba('W19. Resultado con tarjetas: título, destino editable, categorías con su desglose y gestoría que se puede quitar', function (): void {
    [$app, $id, $cookies, $token] = calcularCoche();
    $p = $app->manejar(peticionGet('/c/' . $id))->cuerpo;
    Afirmar::contiene('<h1 class="solo-lectores">Coste de importar Volkswagen Golf a Madrid</h1>', $p);
    Afirmar::contiene('/c/' . $id . '/editar?destino=1#destino', $p, 'el municipio se cambia desde el resultado');
    Afirmar::contiene('Ver detalles técnicos', $p);
    foreach (['Precio del vehículo', 'Impuesto de matriculación', 'IVTM', 'Tasas y gestión'] as $categoria) {
        Afirmar::contiene($categoria, $p, $categoria);
    }
    Afirmar::contiene('Incluir la gestoría (200,00', html_entity_decode($p, ENT_QUOTES, 'UTF-8'));
    Afirmar::verdadero(preg_match('/name="cf_gestoria" value=""\s+checked/', $p) === 1, 'la gestoría empieza incluida');
    // Cambiar el municipio desde el resultado abre el selector
    $ed = $app->manejar(new Peticion('GET', '/c/' . $id . '/editar', ['destino' => '1']))->cuerpo;
    Afirmar::verdadero(preg_match('/<details class="destino[^"]*" id="destino" data-destino open>/', $ed) === 1, 'destino abierto');
    // Quitar la gestoría: cálculo nuevo, 200 € menos y casilla desmarcada
    preg_match('/total-banner__importe[^>]*>([\d.]+,\d{2})/u', html_entity_decode($p, ENT_QUOTES, 'UTF-8'), $total);
    $r = $app->manejar(peticionPost('/c/' . $id . '/costes', ['csrf' => $token, 'cf_gestoria' => '0'], $cookies));
    Afirmar::igual(303, $r->estado);
    $nuevo = $app->manejar(peticionGet($r->cabeceras['Location']))->cuerpo;
    Afirmar::falso(preg_match('/name="cf_gestoria" value=""\s+checked/', $nuevo) === 1, 'la gestoría queda desmarcada');
    preg_match('/total-banner__importe[^>]*>([\d.]+,\d{2})/u', html_entity_decode($nuevo, ENT_QUOTES, 'UTF-8'), $total2);
    $aNum = static fn(string $s): float => (float) str_replace(',', '.', str_replace('.', '', $s));
    Afirmar::igual(200.0, round($aNum($total[1]) - $aNum($total2[1]), 2), 'el total baja lo que costaba la gestoría');
});
