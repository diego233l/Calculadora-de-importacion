<?php

declare(strict_types=1);

use CalculadoraImportacion\Anuncio\Enlace;

prueba('E1. Se aceptan los tres hosts de mobile.de y se reconstruye la URL canónica a partir del id', function (): void {
    $validos = [
        'https://suchen.mobile.de/fahrzeuge/details.html?id=345678901' => '345678901',
        'https://www.mobile.de/fahrzeuge/details.html?id=345678901&cn=DE&ref=quickSearch&searchId=abc-123' => '345678901',
        'https://m.mobile.de/fahrzeuge/details.html?id=345678901' => '345678901',
        'HTTPS://WWW.MOBILE.DE/fahrzeuge/details.html?id=345678901' => '345678901',
        'https://www.mobile.de:443/fahrzeuge/details.html?id=345678901' => '345678901',
        '  https://suchen.mobile.de/fahrzeuge/details.html?id=345678901  ' => '345678901',
        'https://suchen.mobile.de/fahrzeuge/details.html?foo=1&id=345678901#fotos' => '345678901',
        // Id en el path, como en los enlaces antiguos
        'https://suchen.mobile.de/auto-inserat/audi-a3/345678901.html' => '345678901',
        'https://www.mobile.de/auto-inserat/bmw-320d--345678901.html?x=1' => '345678901',
    ];
    foreach ($validos as $enlace => $id) {
        $r = Enlace::validar($enlace);
        Afirmar::verdadero($r['ok'], "debería aceptarse: $enlace");
        Afirmar::igual($id, $r['id'], $enlace);
        Afirmar::igual('https://suchen.mobile.de/fahrzeuge/details.html?id=' . $id, $r['canonica'], $enlace);
    }
});

prueba('E2. Se rechazan otros hosts, IP, localhost, http y subdominios falsos', function (): void {
    $malos = [
        'http://www.mobile.de/fahrzeuge/details.html?id=345678901',          // sin https
        'ftp://www.mobile.de/fahrzeuge/details.html?id=345678901',
        'javascript:alert(1)',
        'data:text/html,<script>alert(1)</script>',
        '//www.mobile.de/fahrzeuge/details.html?id=345678901',               // sin esquema
        'www.mobile.de/fahrzeuge/details.html?id=345678901',
        'https://mobile.de/fahrzeuge/details.html?id=345678901',             // el dominio sin subdominio no está en la lista
        'https://www.autoscout24.de/angebote/x?id=345678901',
        'https://example.com/fahrzeuge/details.html?id=345678901',
        'https://mobile.de.ejemplo.com/fahrzeuge/details.html?id=345678901', // subdominio falso
        'https://www.mobile.de.evil.com/fahrzeuge/details.html?id=345678901',
        'https://evilmobile.de/fahrzeuge/details.html?id=345678901',
        'https://www-mobile.de/fahrzeuge/details.html?id=345678901',
        'https://evil.com/www.mobile.de/fahrzeuge/details.html?id=345678901',
        'https://evil.com/?u=https://www.mobile.de/fahrzeuge/details.html?id=345678901',   // «redirección» hacia mobile.de
        'https://localhost/fahrzeuge/details.html?id=345678901',
        'https://localhost:8080/fahrzeuge/details.html?id=345678901',
        'https://127.0.0.1/fahrzeuge/details.html?id=345678901',
        'https://10.0.0.5/fahrzeuge/details.html?id=345678901',
        'https://169.254.169.254/latest/meta-data/',
        'https://2130706433/fahrzeuge/details.html?id=345678901',           // 127.0.0.1 en decimal
        'https://0x7f000001/fahrzeuge/details.html?id=345678901',
        'https://[::1]/fahrzeuge/details.html?id=345678901',
        'https://[::ffff:127.0.0.1]/x?id=345678901',
    ];
    foreach ($malos as $enlace) {
        Afirmar::falso(Enlace::validar($enlace)['ok'], "debería rechazarse: $enlace");
    }
});

prueba('E3. Se rechazan los trucos de la URL: usuario@host, puertos, barras invertidas, espacios y letras parecidas', function (): void {
    $malos = [
        'https://www.mobile.de@evil.com/fahrzeuge/details.html?id=345678901',
        'https://www.mobile.de:443@evil.com/fahrzeuge/details.html?id=345678901',
        'https://user:pass@www.mobile.de/fahrzeuge/details.html?id=345678901',
        'https://evil.com\\@www.mobile.de/fahrzeuge/details.html?id=345678901',
        'https://www.mobile.de\\.evil.com/fahrzeuge/details.html?id=345678901',
        'https://evil.com#@www.mobile.de/fahrzeuge/details.html?id=345678901',
        'https://www.mobile.de:8443/fahrzeuge/details.html?id=345678901',
        'https://www.mobile.de:22/fahrzeuge/details.html?id=345678901',
        "https://www.mobile.de/fahrzeuge/details.html?id=345678901\nHost: evil.com",
        "https://www.mobile.de/fahrzeuge/details.html?id=345678901\r\nX: y",
        'https://www.mobile.de/fahrzeuge/details.html?id=345678901 http://evil.com',
        "https://www.mobile.de/\x00fahrzeuge/details.html?id=345678901",
        'https://www.mobıle.de/fahrzeuge/details.html?id=345678901',          // «ı» sin punto
        'https://www.mobile.de/fahrzeuge/details.html?id=345678901&u=ñ',
        'https://www.mоbile.de/fahrzeuge/details.html?id=345678901',          // «о» cirílica
    ];
    foreach ($malos as $enlace) {
        Afirmar::falso(Enlace::validar($enlace)['ok'], 'debería rechazarse: ' . addcslashes($enlace, "\0..\37"));
    }
});

prueba('E4. Sin número de anuncio válido no se acepta, y el mensaje es claro', function (): void {
    foreach ([
        'https://www.mobile.de/',
        'https://www.mobile.de/fahrzeuge/details.html',
        'https://www.mobile.de/fahrzeuge/details.html?id=abc',
        'https://www.mobile.de/fahrzeuge/details.html?id=12345',          // demasiado corto
        'https://www.mobile.de/fahrzeuge/details.html?id=1234567890123',   // demasiado largo
        'https://www.mobile.de/fahrzeuge/details.html?id[]=345678901',
        'https://www.mobile.de/fahrzeuge/details.html?id=345678901%27%20OR%201=1',
    ] as $enlace) {
        $r = Enlace::validar($enlace);
        Afirmar::falso($r['ok'], $enlace);
        Afirmar::verdadero(isset($r['error']) && $r['error'] !== '');
    }
    Afirmar::contiene('número del anuncio', Enlace::validar('https://www.mobile.de/')['error']);
    Afirmar::contiene('Pega el enlace', Enlace::validar('   ')['error']);
    Afirmar::contiene('demasiado largo', Enlace::validar('https://www.mobile.de/fahrzeuge/details.html?id=345678901&x=' . str_repeat('a', 3000))['error']);
});

prueba('E5. La URL canónica nunca contiene nada de lo que pegó el usuario salvo el id', function (): void {
    $r = Enlace::validar('https://www.mobile.de/fahrzeuge/details.html?id=345678901&redirect=http://evil.com/&x=<script>');
    Afirmar::verdadero($r['ok']);
    Afirmar::igual('https://suchen.mobile.de/fahrzeuge/details.html?id=345678901', $r['canonica']);
    Afirmar::falso(str_contains($r['canonica'], 'evil'));
    Afirmar::verdadero(preg_match('#^https://suchen\.mobile\.de/fahrzeuge/details\.html\?id=\d{6,12}$#', $r['canonica']) === 1);
});
