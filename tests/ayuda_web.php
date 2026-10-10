<?php

declare(strict_types=1);

use CalculadoraImportacion\Anuncio\Descargador;
use CalculadoraImportacion\Web\App;
use CalculadoraImportacion\Web\Csrf;
use CalculadoraImportacion\Web\Peticion;
use CalculadoraImportacion\Web\Respuesta;

/** Directorio temporal propio de cada ejecución; se borra al terminar. */
function dirVarPrueba(string $sufijo = ''): string
{
    static $base = null;
    if ($base === null) {
        $base = sys_get_temp_dir() . '/calculadora-prueba-' . bin2hex(random_bytes(4));
        register_shutdown_function(static function () use ($base): void {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach (is_dir($base) ? $it : [] as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($base);
        });
    }
    $dir = $base . ($sufijo !== '' ? '/' . $sufijo : '');
    @mkdir($dir, 0700, true);
    return $dir;
}

/**
 * Descargador falso: ningún test llama a mobile.de. Devuelve las respuestas que se le indiquen, de una en una (la última se repite),
 * y cuenta cuántas veces se le ha pedido algo.
 */
final class DescargadorFalso implements Descargador
{
    public int $llamadas = 0;
    /** @var list<string> */
    public array $urls = [];

    /** @param list<array{ok: bool, estado: ?int, cuerpo: ?string, tipo_error: ?string}> $respuestas */
    public function __construct(private array $respuestas = [])
    {
        $this->respuestas ??= [];
        if ($this->respuestas === []) {
            $this->respuestas = [self::bloqueado()];
        }
    }

    public static function bloqueado(): array
    {
        return ['ok' => false, 'estado' => 403, 'cuerpo' => null, 'tipo_error' => 'bloqueado'];
    }

    public static function pagina(string $html): array
    {
        return ['ok' => true, 'estado' => 200, 'cuerpo' => $html, 'tipo_error' => null];
    }

    public function obtener(string $url): array
    {
        $this->llamadas++;
        $this->urls[] = $url;
        return count($this->respuestas) > 1 ? array_shift($this->respuestas) : $this->respuestas[0];
    }
}

/** Aplicación con su propio almacén vacío, la fecha fijada a 10/10/2026 y un descargador que se declara bloqueado. */
function appPrueba(?string $dir = null, ?Descargador $descargador = null): App
{
    return new App($dir ?? dirVarPrueba(bin2hex(random_bytes(3))), static fn(): string => '2026-10-10', $descargador ?? new DescargadorFalso());
}

function peticionGet(string $ruta, array $consulta = [], array $cookies = [], string $ip = '10.0.0.1'): Peticion
{
    return new Peticion('GET', $ruta, $consulta, [], $cookies, $ip);
}

function peticionPost(string $ruta, array $post, array $cookies = [], string $ip = '10.0.0.1'): Peticion
{
    return new Peticion('POST', $ruta, [], $post, $cookies, $ip, false, 200);
}

/** Pide la portada y devuelve [cookie, token] de CSRF válidos para ese almacén. */
function sesionCsrf(App $app): array
{
    $r = $app->manejar(peticionGet('/'));
    $cookie = $r->cookies[0]['valor'] ?? '';
    preg_match('/name="csrf" value="([a-f0-9]{64})"/', $r->cuerpo, $m);
    return [[Csrf::COOKIE => $cookie], $m[1] ?? ''];
}

/** Datos de un coche completo y válido (diésel de particular, Madrid, 38.000 € de precio medio del BOE). */
function datosCoche(array $cambios = []): array
{
    return array_merge([
        'enlace' => 'https://suchen.mobile.de/fahrzeuge/details.html?id=345678901',
        'municipio' => '28079', 'municipio_texto' => 'Madrid',
        'marca' => 'Volkswagen', 'modelo' => 'Golf', 'version' => '2.0 TDI',
        'matriculacion' => '03/2021', 'km' => '80.000', 'combustible' => 'diesel',
        'cc' => '1.968', 'cilindros' => '4', 'kw' => '', 'cv' => '150', 'co2' => '170',
        'precio' => '18.000', 'vendedor' => 'particular', 'cp_vendedor' => '80331', 'boe_precio' => '38.000',
    ], $cambios);
}

/** Calcula un coche y devuelve [App, id del cálculo, cookies, token]. */
function calcularCoche(array $cambios = [], ?App $app = null): array
{
    $app ??= appPrueba();
    [$cookies, $token] = sesionCsrf($app);
    $r = $app->manejar(peticionPost('/calcular', ['csrf' => $token] + datosCoche($cambios), $cookies));
    $id = preg_match('#^/c/([a-f0-9]{12})$#', $r->cabeceras['Location'] ?? '', $m) ? $m[1] : '';
    return [$app, $id, $cookies, $token, $r];
}
