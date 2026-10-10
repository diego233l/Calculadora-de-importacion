<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

use CalculadoraImportacion\Anuncio\Analizador;
use CalculadoraImportacion\Anuncio\Descargador;
use CalculadoraImportacion\Anuncio\DescargadorCurl;
use CalculadoraImportacion\Anuncio\Enlace;
use CalculadoraImportacion\Boe\Servicio as ServicioBoe;
use CalculadoraImportacion\Calculo\Calculadora;
use CalculadoraImportacion\Calculo\EntradaInvalida;
use CalculadoraImportacion\Datos\Tablas;

require_once __DIR__ . '/ayudas.php';

/**
 * La aplicación web: recibe una Peticion, devuelve una Respuesta. No toca variables globales ni emite nada,
 * así que los tests la llaman directamente. El código se lee del repositorio en cada petición.
 */
final class App
{
    private const GUIAS = [
        'importar-coche-de-alemania' => ['Importar un coche desde Alemania: paso a paso', 'guia_importar'],
        'impuesto-de-matriculacion' => ['El impuesto de matriculación (IEDMT)', 'guia_iedmt'],
        'itp' => ['El ITP al comprar un coche a un particular', 'guia_itp'],
    ];
    private const DESCRIPCIONES_GUIAS = [
        'importar-coche-de-alemania' => 'Qué comprobar antes de comprar, qué papeles pedir y qué impuestos y trámites hay en España al importar un coche desde Alemania.',
        'impuesto-de-matriculacion' => 'Cómo se calcula el impuesto de matriculación (IEDMT): tipos por emisiones de CO₂, valor del BOE, depreciación y descuento para coches ya matriculados fuera.',
        'itp' => 'Cuándo se paga el ITP al comprar un coche usado a un particular, sobre qué valor y a qué tipo según la comunidad autónoma.',
    ];

    private ?array $tablas = null;
    private ?Almacen $almacen = null;
    private ?ServicioBoe $boe = null;
    private bool $boeCargado = false;
    private ?LectorAnuncios $lector = null;
    /** @var \Closure(): string */
    private \Closure $hoy;

    /**
     * @param (\Closure(): string)|null $hoy devuelve la fecha de hoy como AAAA-MM-DD (los tests la fijan)
     * @param Descargador|null $descargador quien descarga los anuncios (los tests ponen uno falso; nunca se llama a mobile.de desde ellos)
     */
    public function __construct(private readonly string $dirVar, ?\Closure $hoy = null, private readonly ?Descargador $descargador = null)
    {
        $this->hoy = $hoy ?? static function (): string {
            return (new \DateTimeImmutable('now', new \DateTimeZone(Config::ZONA_HORARIA)))->format('Y-m-d');
        };
    }

    public function manejar(Peticion $p): Respuesta
    {
        try {
            $respuesta = $this->enrutar($p);
        } catch (\RuntimeException $e) {
            Registro::escribir($this->dirVar, 'error_almacen', $e->getMessage());
            $respuesta = $this->error(503, 'Servicio no disponible', 'No podemos guardar datos ahora mismo. Vuelve a intentarlo en unos minutos.');
        } catch (\Throwable $e) {
            Registro::escribir($this->dirVar, 'error_interno', get_class($e) . ': ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
            $respuesta = $this->error(500, 'Algo ha fallado', 'Ha ocurrido un error inesperado. Si se repite, avísanos.');
        }
        return $this->conCabeceras($respuesta);
    }

    // ------------------------------------------------------------------ rutas

    private function enrutar(Peticion $p): Respuesta
    {
        $metodo = $p->metodo === 'HEAD' ? 'GET' : $p->metodo;
        $ruta = $p->ruta;
        if (strlen($ruta) > 200) {
            return $this->error(404, 'Página no encontrada', 'No existe esa página.');
        }
        if ($p->longitudCuerpo > Config::MAX_CUERPO) {
            return $this->error(413, 'Formulario demasiado grande', 'Lo que has enviado es demasiado grande.');
        }

        $ids = '([a-f0-9]{12})';
        $rutas = [
            ['GET', '#^/$#', 'inicio'],
            ['GET', '#^/datos$#', 'datosGet'],
            ['POST', '#^/datos$#', 'datosPost'],
            ['POST', '#^/calcular$#', 'calcular'],
            ['GET', '#^/c/' . $ids . '$#', 'resultado'],
            ['GET', '#^/c/' . $ids . '/editar$#', 'editar'],
            ['POST', '#^/c/' . $ids . '/costes$#', 'ajustar'],
            ['POST', '#^/c/' . $ids . '/boe$#', 'cambiarFilaBoe'],
            ['GET', '#^/comparar$#', 'comparar'],
            ['GET', '#^/historial$#', 'historial'],
            ['GET', '#^/api/municipios$#', 'apiMunicipios'],
            ['GET', '#^/guias$#', 'guias'],
            ['GET', '#^/guias/([a-z0-9-]{1,60})$#', 'guia'],
            ['GET', '#^/aviso-legal$#', 'avisoLegal'],
            ['GET', '#^/privacidad$#', 'privacidad'],
            ['GET', '#^/salud$#', 'salud'],
        ];
        $rutaConocida = false;
        foreach ($rutas as [$m, $patron, $accion]) {
            if (preg_match($patron, $ruta, $coincidencias)) {
                $rutaConocida = true;
                if ($m === $metodo) {
                    array_shift($coincidencias);
                    return $this->$accion($p, ...$coincidencias);
                }
            }
        }
        if ($rutaConocida) {
            return $this->error(405, 'Método no permitido', 'Esa página no admite esta operación.')->conCabecera('Allow', 'GET, POST');
        }
        return $this->error(404, 'Página no encontrada', 'No existe esa página. Puede que el enlace esté mal copiado.');
    }

    // ------------------------------------------------------------------ páginas

    private function inicio(Peticion $p): Respuesta
    {
        $r = new Respuesta();
        $csrf = Csrf::emitir($p, $r, $this->almacen()->secreto());
        $r->cuerpo = Vista::pagina('inicio', [
            'csrf' => $csrf, 'valores' => ['enlace' => '', 'texto_anuncio' => ''], 'errores' => [],
        ], [
            'titulo' => 'Calculadora de importación de coches de mobile.de a España',
            'descripcion' => 'Calcula el coste real de importar a España un coche de mobile.de: impuesto de matriculación, ITP o IVA, IVTM, tasas, gestoría y transporte, con la fuente de cada cifra.',
            'ruta' => '/', 'activa' => 'inicio',
        ]);
        $r->cabeceras['Content-Type'] = 'text/html; charset=utf-8';
        return $r->conCabecera('Cache-Control', 'no-cache');
    }

    /** Rellenar los datos a mano, sin leer ningún anuncio. */
    private function datosGet(Peticion $p): Respuesta
    {
        $r = new Respuesta();
        $csrf = Csrf::emitir($p, $r, $this->almacen()->secreto());
        $v = array_fill_keys(Formulario::CAMPOS, '');
        $r->cuerpo = $this->paginaDatos($csrf, $v, [], [], $this->tablas());
        $r->cabeceras['Content-Type'] = 'text/html; charset=utf-8';
        return $r->conCabecera('Cache-Control', 'no-store');
    }

    /** Primer paso: enlace (o texto del anuncio) y municipio. Con ellos se muestra el formulario de datos del coche, ya rellenado. */
    private function datosPost(Peticion $p): Respuesta
    {
        $almacen = $this->almacen();
        if (!Csrf::validar($p, $almacen->secreto())) {
            return $this->errorCsrf();
        }
        $tablas = $this->tablas();
        $enlace = Peticion::texto($p->post, 'enlace', 2100);
        $texto = Peticion::textoLargo($p->post, 'texto_anuncio', Config::MAX_TEXTO_ANUNCIO);
        $errores = [];
        $validado = null;
        if ($enlace !== '') {
            $e = Enlace::validar($enlace);
            if ($e['ok']) {
                $validado = $e;
            } elseif ($texto === '') {
                $errores['enlace'] = $e['error'];           // con el texto pegado, un enlace malo no impide nada
            } else {
                $enlace = '';
            }
        }
        if ($enlace === '' && $texto === '') {
            $errores['enlace'] = 'Pega el enlace del anuncio de mobile.de (o, si no funciona, el texto del anuncio).';
        }
        // El municipio de destino se elige en el paso siguiente; si ya venía elegido (p. ej. al pegar el texto), se conserva
        $m = Municipios::resolver($tablas, Peticion::texto($p->post, 'municipio', 5), Peticion::texto($p->post, 'municipio_texto', 80));
        $valores = [
            'enlace' => $validado !== null ? $validado['canonica'] : $enlace, 'municipio' => (string) $m['ine'],
            'municipio_texto' => $m['ine'] !== null ? Municipios::etiqueta($tablas, $m['ine']) : '',
        ];
        $r = new Respuesta();
        $csrf = Csrf::emitir($p, $r, $almacen->secreto());
        if ($errores) {
            $r->estado = 422;
            $r->cuerpo = Vista::pagina('inicio', ['csrf' => $csrf, 'valores' => $valores + ['texto_anuncio' => $texto], 'errores' => $errores], [
                'titulo' => 'Revisa los datos – Calculadora de importación', 'ruta' => '/', 'robots' => 'noindex', 'activa' => 'inicio',
            ]);
        } else {
            $v = array_merge(array_fill_keys(Formulario::CAMPOS, ''), $valores);
            $anuncio = ['estado' => 'ninguno'];
            $lectura = null;
            if ($texto !== '' || $validado !== null) {
                $res = $texto !== ''
                    ? $this->lector()->leerTexto($texto, ($this->hoy)())
                    : $this->lector()->leerEnlace($p->ip, $validado, ($this->hoy)());
                $anuncio = ['estado' => $res['estado'], 'mensaje' => $res['mensaje'], 'fuente' => $texto !== '' ? 'texto' : 'enlace'];
                $lectura = $res['lectura'];
            }
            if ($lectura !== null) {
                $lectura = $this->completarConBoe($lectura, $tablas);
                $v = Formulario::valoresDesdeLectura($lectura, $v);
                $anuncio['avisos'] = $lectura['avisos'];
                $anuncio['revisar'] = self::notasDeRevision($lectura);
            }
            $r->cuerpo = $this->paginaDatos($csrf, $v, [], [], $tablas, null, $anuncio);
        }
        $r->cabeceras['Content-Type'] = 'text/html; charset=utf-8';
        return $r->conCabecera('Cache-Control', 'no-store');
    }

    private function calcular(Peticion $p): Respuesta
    {
        $almacen = $this->almacen();
        if (!Csrf::validar($p, $almacen->secreto())) {
            return $this->errorCsrf();
        }
        $tablas = $this->tablas();
        $f = Formulario::leerDatos($p->post, $tablas, ($this->hoy)());
        if ($f['errores']) {
            $r = new Respuesta(422);
            $csrf = Csrf::emitir($p, $r, $almacen->secreto());
            $r->cuerpo = $this->paginaDatos($csrf, $f['valores'], $f['errores'], $f['candidatos'], $tablas);
            $r->cabeceras['Content-Type'] = 'text/html; charset=utf-8';
            return $r->conCabecera('Cache-Control', 'no-store');
        }
        return $this->guardarYRedirigir($p, $this->conFilaBoe($f['entrada'], $tablas), $tablas);
    }

    private function resultado(Peticion $p, string $id): Respuesta
    {
        $calculo = $this->almacen()->leerCalculo($id);
        if ($calculo === null) {
            return $this->error(404, 'Cálculo no encontrado', 'Ese cálculo no existe o ha caducado. Los cálculos se conservan ' . Config::DIAS_CONSERVACION . ' días.');
        }
        return $this->paginaResultado($p, $calculo, [], []);
    }

    private function editar(Peticion $p, string $id): Respuesta
    {
        $almacen = $this->almacen();
        $calculo = $almacen->leerCalculo($id);
        if ($calculo === null) {
            return $this->error(404, 'Cálculo no encontrado', 'Ese cálculo no existe o ha caducado.');
        }
        $tablas = $this->tablas();
        $r = new Respuesta();
        $csrf = Csrf::emitir($p, $r, $almacen->secreto());
        $r->cuerpo = $this->paginaDatos($csrf, Formulario::valoresDesdeEntrada($calculo['entrada'], $tablas), [], [], $tablas, $id, ['estado' => 'ninguno', 'abrir_destino' => isset($p->consulta['destino'])]);
        $r->cabeceras['Content-Type'] = 'text/html; charset=utf-8';
        return $r->conCabecera('Cache-Control', 'no-store');
    }

    private function ajustar(Peticion $p, string $id): Respuesta
    {
        $almacen = $this->almacen();
        if (!Csrf::validar($p, $almacen->secreto())) {
            return $this->errorCsrf();
        }
        $calculo = $almacen->leerCalculo($id);
        if ($calculo === null) {
            return $this->error(404, 'Cálculo no encontrado', 'Ese cálculo no existe o ha caducado.');
        }
        $a = Formulario::leerAjustes($p->post, $calculo['entrada']);
        if ($a['errores']) {
            return $this->paginaResultado($p, $calculo, $a['errores'], $a['valores'], 422);
        }
        return $this->guardarYRedirigir($p, $a['entrada'], $this->tablas());
    }

    /** El usuario elige otra de las filas parecidas del BOE: se recalcula con ella y se guarda como cálculo nuevo. */
    private function cambiarFilaBoe(Peticion $p, string $id): Respuesta
    {
        $almacen = $this->almacen();
        if (!Csrf::validar($p, $almacen->secreto())) {
            return $this->errorCsrf();
        }
        $calculo = $almacen->leerCalculo($id);
        if ($calculo === null) {
            return $this->error(404, 'Cálculo no encontrado', 'Ese cálculo no existe o ha caducado.');
        }
        $tablas = $this->tablas();
        $boe = $this->boe($tablas);
        $fila = $boe !== null ? $boe->fila((int) Peticion::texto($p->post, 'fila', 10)) : null;
        if ($fila === null || !$boe->esDeLaMarca($fila, $calculo['entrada'])) {
            return $this->error(422, 'Fila no válida', 'Esa fila del BOE no corresponde a la marca de este coche. Vuelve al resultado y elige una de las filas propuestas.');
        }
        return $this->guardarYRedirigir($p, $boe->aplicar($calculo['entrada'], $fila, 'usuario'), $tablas);
    }

    private function comparar(Peticion $p): Respuesta
    {
        // ?c=id1,id2,id3 (PHP solo conserva el último valor de ?c=a&c=b, así que se usa una lista con comas)
        $ids = $p->consulta['c'] ?? '';
        $ids = is_array($ids) ? $ids : explode(',', mb_substr((string) $ids, 0, 80));
        $calculos = [];
        foreach (array_slice($ids, 0, 3) as $id) {
            if (is_string($id) && ($c = $this->almacen()->leerCalculo($id)) !== null) {
                $calculos[] = $c;
            }
        }
        $r = Respuesta::html(Vista::pagina('comparar', ['calculos' => $calculos, 'pedidos' => count($ids)], [
            'titulo' => 'Comparar coches – Calculadora de importación', 'ruta' => '/comparar', 'robots' => 'noindex', 'activa' => 'historial',
        ]));
        return $r->conCabecera('Cache-Control', 'no-store');
    }

    private function historial(Peticion $p): Respuesta
    {
        return Respuesta::html(Vista::pagina('historial', [], [
            'titulo' => 'Mi historial – Calculadora de importación', 'ruta' => '/historial', 'robots' => 'noindex', 'activa' => 'historial',
        ]));
    }

    private function apiMunicipios(Peticion $p): Respuesta
    {
        $q = Peticion::texto($p->consulta, 'q', 60);
        $res = $q === '' ? [] : Municipios::buscar($this->tablas(), $q, 8);
        return Respuesta::json(['resultados' => $res])->conCabecera('Cache-Control', 'public, max-age=3600');
    }

    private function guias(Peticion $p): Respuesta
    {
        return Respuesta::html(Vista::pagina('guias', ['guias' => self::GUIAS], [
            'titulo' => 'Guías para importar un coche – Calculadora de importación',
            'descripcion' => 'Guías prácticas: importar un coche desde Alemania, impuesto de matriculación e ITP.',
            'ruta' => '/guias', 'activa' => 'guias',
        ]))->conCabecera('Cache-Control', 'public, max-age=900');
    }

    private function guia(Peticion $p, string $slug): Respuesta
    {
        if (!isset(self::GUIAS[$slug])) {
            return $this->error(404, 'Guía no encontrada', 'No existe esa guía.');
        }
        [$titulo, $vista] = self::GUIAS[$slug];
        return Respuesta::html(Vista::pagina($vista, ['guias' => self::GUIAS, 'slug' => $slug, 'titulo' => $titulo], [
            'titulo' => $titulo . ' – Calculadora de importación', 'descripcion' => self::DESCRIPCIONES_GUIAS[$slug],
            'ruta' => '/guias/' . $slug, 'activa' => 'guias',
        ]))->conCabecera('Cache-Control', 'public, max-age=900');
    }

    private function avisoLegal(Peticion $p): Respuesta
    {
        return Respuesta::html(Vista::pagina('aviso_legal', ['sitio' => Config::sitio()], [
            'titulo' => 'Aviso legal – Calculadora de importación', 'ruta' => '/aviso-legal',
            'descripcion' => 'Titular del sitio, naturaleza orientativa de los cálculos y limitación de responsabilidad de la Calculadora de importación.',
        ]))->conCabecera('Cache-Control', 'public, max-age=900');
    }

    private function privacidad(Peticion $p): Respuesta
    {
        return Respuesta::html(Vista::pagina('privacidad', ['sitio' => Config::sitio(), 'dias' => Config::DIAS_CONSERVACION], [
            'titulo' => 'Política de privacidad – Calculadora de importación', 'ruta' => '/privacidad',
            'descripcion' => 'Qué datos trata la Calculadora de importación, durante cuánto tiempo y cómo ejercer tus derechos. Sin cuentas ni cookies de seguimiento.',
        ]))->conCabecera('Cache-Control', 'public, max-age=900');
    }

    private function salud(Peticion $p): Respuesta
    {
        return Respuesta::texto("ok\n")->conCabecera('Cache-Control', 'no-store');
    }

    // ------------------------------------------------------------------ piezas comunes

    private function paginaDatos(string $csrf, array $valores, array $errores, array $candidatos, array $tablas, ?string $editando = null, ?array $anuncio = null): string
    {
        return Vista::pagina('datos', [
            'csrf' => $csrf, 'v' => $valores, 'errores' => $errores, 'candidatos' => $candidatos, 'editando' => $editando, 'anuncio' => $anuncio,
            'combustibles' => self::COMBUSTIBLES, 'marcas' => $this->boe($tablas)?->marcas()->nombresVisibles() ?? [],
        ], [
            'titulo' => ($errores ? 'Revisa los datos' : 'Datos del coche') . ' – Calculadora de importación',
            'ruta' => '/datos', 'robots' => 'noindex', 'activa' => 'inicio',
        ]);
    }

    public const COMBUSTIBLES = [
        'gasolina' => 'Gasolina', 'diesel' => 'Diésel', 'hibrido' => 'Híbrido (HEV, no enchufable)',
        'phev' => 'Híbrido enchufable (PHEV)', 'electrico' => 'Eléctrico', 'glp' => 'GLP (autogas)', 'gnc' => 'GNC (gas natural)',
    ];

    private function paginaResultado(Peticion $p, array $calculo, array $erroresAjuste, array $valoresAjuste, int $estado = 200): Respuesta
    {
        $almacen = $this->almacen();
        $r = new Respuesta($estado);
        $csrf = Csrf::emitir($p, $r, $almacen->secreto());
        $res = $calculo['resultado'];
        $entrada = $calculo['entrada'];
        $tablas = $this->tablas();
        $titulo = trim(($entrada['marca'] ?? '') . ' ' . ($entrada['modelo'] ?? '') . ' ' . ($entrada['version'] ?? ''));
        $url = ($p->host !== '' ? ($p->https ? 'https://' : 'http://') . $p->host : '') . '/c/' . $calculo['id'];
        $r->cuerpo = Vista::pagina('resultado', [
            'id' => $calculo['id'], 'creado' => $calculo['creado'], 'entrada' => $entrada, 'res' => $res,
            'titulo' => $titulo !== '' ? $titulo : 'Tu coche', 'url' => $url, 'csrf' => $csrf,
            'resumen' => self::resumen($res), 'municipio' => Municipios::etiqueta($tablas, (string) ($entrada['municipio'] ?? '')),
            'errores' => $erroresAjuste, 'ajuste' => $valoresAjuste,
            'costesDefecto' => $tablas['costes_fijos']['partidas'], 'transporteDefecto' => $tablas['transporte'],
            'resumenJson' => self::resumenParaHistorial($calculo, $titulo, $url),
            'panelBoe' => $this->panelBoe($entrada, $res, $tablas),
            'coche' => TarjetaCoche::desdeValores(Formulario::valoresDesdeEntrada($entrada, $tablas)),
            'ajustesActuales' => Formulario::ajustesActuales($entrada, ['gestoria']),
        ], [
            'titulo' => 'Coste de importar ' . ($titulo !== '' ? $titulo : 'tu coche') . ' – Calculadora de importación',
            'ruta' => '/c/' . $calculo['id'], 'robots' => 'noindex', 'activa' => 'inicio', 'clase' => 'pagina-resultado',
        ]);
        $r->cabeceras['Content-Type'] = 'text/html; charset=utf-8';
        return $r->conCabecera('Cache-Control', 'private, no-cache');
    }

    /** Agrupa las partidas para el total, la barra y el texto de comparación. */
    public static function resumen(array $res): array
    {
        $grupos = ['precio' => 0.0, 'impuestos' => 0.0, 'gestion' => 0.0, 'transporte' => 0.0];
        foreach ($res['partidas'] as $p) {
            if ($p['estado'] !== 'ok') {
                continue;
            }
            $g = match (true) {
                $p['clave'] === 'precio' => 'precio',
                in_array($p['clave'], ['iedmt', 'itp_iva', 'ivtm'], true) => 'impuestos',
                in_array($p['clave'], ['transporte', 'otros_gastos_viaje'], true) => 'transporte',
                default => 'gestion',
            };
            $grupos[$g] += (float) $p['importe'];
        }
        $extra = $res['coste_sobre_precio'];
        $porcentaje = $res['precio_anuncio'] > 0 ? round($extra / $res['precio_anuncio'] * 100, 1) : 0.0;
        return ['grupos' => $grupos, 'extra' => $extra, 'porcentaje' => $porcentaje];
    }

    /** Datos mínimos que el navegador guarda en su historial (localStorage). */
    private static function resumenParaHistorial(array $calculo, string $titulo, string $url): string
    {
        $res = $calculo['resultado'];
        return (string) json_encode([
            'id' => $calculo['id'],
            'titulo' => $titulo !== '' ? $titulo : 'Coche sin nombre',
            'total' => $res['total'],
            'precio' => $res['precio_anuncio'],
            'completo' => $res['completo'],
            'fecha' => $res['meta']['calculado'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function guardarYRedirigir(Peticion $p, array $entrada, array $tablas): Respuesta
    {
        $almacen = $this->almacen();
        if (!$almacen->permitir('calc:' . $p->ip, Config::LIMITE_CALCULOS_POR_MINUTO)) {
            Registro::escribir($this->dirVar, 'limite_calculos');
            return $this->error(429, 'Demasiadas peticiones', 'Has hecho muchos cálculos seguidos. Espera un minuto y vuelve a intentarlo.')->conCabecera('Retry-After', '60');
        }
        try {
            $resultado = Calculadora::calcular($entrada, $tablas);
        } catch (EntradaInvalida $e) {
            return $this->error(422, 'Datos no válidos', implode(' ', $e->errores));
        }
        $resultado['meta'] = ['calculado' => ($this->hoy)(), 'anio_tablas' => $tablas['anio']];
        array_push($resultado['avisos'], ...$this->avisosBoe($entrada, $tablas));
        $id = $almacen->guardarCalculo($entrada, $resultado);
        if (random_int(1, 100) === 1) {
            $almacen->purgar(Config::DIAS_CONSERVACION);
        }
        return Respuesta::redirigir('/c/' . $id);
    }

    // ------------------------------------------------------------------ lectura del anuncio

    private function lector(): LectorAnuncios
    {
        return $this->lector ??= new LectorAnuncios(
            $this->almacen(), $this->dirVar, Analizador::crear(Config::dirData()), $this->descargador ?? new DescargadorCurl()
        );
    }

    /**
     * Lo que el anuncio no trae (cilindrada y cilindros) se toma de la fila del BOE que mejor encaja, si encaja bien,
     * y se marca para que el usuario lo revise.
     *
     * @param array<string, mixed> $lectura
     * @return array<string, mixed>
     */
    private function completarConBoe(array $lectura, array $tablas): array
    {
        $boe = $this->boe($tablas);
        $c = $lectura['campos'];
        if ($boe === null || isset($c['cc']) && isset($c['cilindros']) || ($c['combustible']['valor'] ?? '') === 'electrico') {
            return $lectura;
        }
        $mat = preg_match('#^(\d{2})/(\d{4})$#', $c['matriculacion']['valor'] ?? '', $m) ? $m[2] . '-' . $m[1] . '-01' : null;
        $entrada = [
            'marca' => $c['marca']['valor'] ?? '', 'modelo' => $c['modelo']['valor'] ?? '', 'version' => $c['version']['valor'] ?? '',
            'fecha_matriculacion' => $mat, 'combustible' => $c['combustible']['valor'] ?? null,
            'cc' => isset($c['cc']) ? (int) $c['cc']['valor'] : null,
            'cv' => isset($c['cv']) ? (float) $c['cv']['valor'] : null,
            'kw' => isset($c['kw']) ? (float) str_replace(',', '.', $c['kw']['valor']) : null,
        ];
        $mejor = $boe->candidatas($entrada, 1)['candidatas'][0] ?? null;
        if ($mejor === null || $mejor['puntuacion'] < \CalculadoraImportacion\Boe\Emparejador::UMBRAL_MEDIA) {
            return $lectura;
        }
        $fila = $mejor['fila'];
        $nota = 'Lo hemos tomado de la fila «' . $fila['modelo_tipo'] . '» de la Orden del BOE.';
        foreach (['cc' => $fila['cc'], 'cilindros' => $fila['cilindros']] as $campo => $valor) {
            if (!isset($c[$campo]) && $valor !== null) {
                $c[$campo] = ['valor' => (string) $valor, 'confianza' => 0.7, 'origen' => 'BOE', 'nota' => $nota];
            }
        }
        $lectura['campos'] = $c;
        $lectura['dudosos'] = array_keys(array_filter($c, static fn(array $f): bool => $f['confianza'] < Analizador::UMBRAL_SEGURO));
        $lectura['faltan'] = array_values(array_filter($lectura['faltan'], static fn(string $f): bool => !isset($c[$f])));
        return $lectura;
    }

    /**
     * Texto «Revisa:» de cada campo que el usuario debe mirar: los leídos con poca confianza y los que faltan.
     *
     * @param array<string, mixed> $lectura
     * @return array<string, string>
     */
    private static function notasDeRevision(array $lectura): array
    {
        $origen = [
            'estructurado' => 'los datos estructurados del anuncio', 'etiqueta' => 'la ficha del anuncio', 'título' => 'el título del anuncio',
            'regex' => 'una búsqueda en el texto del anuncio', 'derivado' => 'otro dato del anuncio', 'BOE' => 'la Orden del BOE',
        ];
        $n = [];
        foreach ($lectura['dudosos'] as $campo) {
            $f = $lectura['campos'][$campo];
            $n[$campo] = ($f['nota'] ?? null) !== null && $f['nota'] !== ''
                ? $f['nota']
                : 'Lo hemos deducido de ' . ($origen[$f['origen']] ?? 'el anuncio') . ' y no estamos seguros (confianza ' . (int) round($f['confianza'] * 100) . ' %).';
        }
        foreach ($lectura['faltan'] as $campo) {
            $n[$campo] = 'No lo hemos encontrado en el anuncio: complétalo tú.';
        }
        return $n;
    }

    // ------------------------------------------------------------------ fila del BOE

    /** La base del BOE del año de las tablas, o null si no está (entonces solo vale el precio medio escrito a mano). */
    private function boe(array $tablas): ?ServicioBoe
    {
        if (!$this->boeCargado) {
            $this->boe = ServicioBoe::crear(Config::dirData(), (int) $tablas['anio']);
            $this->boeCargado = true;
        }
        return $this->boe;
    }

    /** Si el usuario no ha escrito el precio medio, busca la fila del BOE que más se parece. */
    private function conFilaBoe(array $entrada, array $tablas): array
    {
        $boe = $this->boe($tablas);
        return $boe === null ? $entrada : $boe->elegirAutomaticamente($entrada);
    }

    /** Avisos sobre cómo se ha obtenido la fila del BOE (se guardan con el resultado). */
    private function avisosBoe(array $entrada, array $tablas): array
    {
        $b = $entrada['boe'] ?? null;
        if (($b['origen'] ?? null) === 'auto') {
            $pct = (int) round(((float) $b['puntuacion']) * 100);
            if (($b['confianza'] ?? '') === 'alta') {
                return [\CalculadoraImportacion\Calculo\Partida::aviso(
                    'boe_automatica',
                    "Hemos buscado tu modelo en la Orden del BOE y la fila «{$b['modelo_tipo']}» encaja muy bien ($pct % de coincidencia). De ella salen el valor venal y, con él, el impuesto de matriculación y el ITP; puedes cambiarla en «Otros modelos posibles».",
                    'info'
                )];
            }
            // Sin coincidencia alta se dice qué falla, para que nadie dé por buena una fila que no es la suya
            $motivo = '';
            $boe = $this->boe($tablas);
            $fila = $boe !== null ? $boe->fila((int) $b['id']) : null;
            $e = $fila !== null ? $boe->evaluar($entrada, $fila) : null;
            foreach ($e['motivos'] ?? [] as $m) {
                if (str_starts_with($m, 'la versión')) {
                    $motivo = ' (' . $m . ')';
                }
            }
            return [\CalculadoraImportacion\Calculo\Partida::aviso(
                'boe_automatica',
                "No hemos encontrado tu versión exacta en la Orden del BOE{$motivo}. Hemos usado la fila más parecida, «{$b['modelo_tipo']}» ($pct % de coincidencia), y de ella salen el valor venal, el impuesto de matriculación y el ITP. Si tu coche es otro, elígelo en «Otros modelos posibles» o escribe a mano el precio medio de tu versión.",
                'atencion'
            )];
        }
        if ($b === null && $this->boe($tablas) !== null) {
            $sinDatos = trim((string) ($entrada['marca'] ?? '')) === '' || trim((string) ($entrada['modelo'] ?? '')) === '';
            return [\CalculadoraImportacion\Calculo\Partida::aviso(
                'boe_sin_fila',
                $sinDatos
                    ? 'No has indicado marca y modelo, así que no hemos podido buscar el coche en la Orden del BOE. Edita los datos y añádelos, o escribe el precio medio del BOE a mano.'
                    : 'No hemos encontrado tu coche en la Orden del BOE de ' . $tablas['anio'] . ' con la seguridad suficiente. Edita los datos (marca, modelo y versión tal como los escribe el BOE) o escribe a mano el precio medio de tu modelo.',
                'atencion'
            )];
        }
        return [];
    }

    /**
     * Lo que enseña el apartado «Fila del BOE» del resultado: la fila usada y las más parecidas, cada una con el total
     * que saldría si se eligiera esa.
     *
     * @return array{disponible: bool, actual: ?array, filas: list<array<string, mixed>>, anio: int}
     */
    private function panelBoe(array $entrada, array $res, array $tablas): array
    {
        $boe = $this->boe($tablas);
        if ($boe === null) {
            return ['disponible' => false, 'actual' => null, 'filas' => [], 'anio' => (int) $tablas['anio']];
        }
        $actual = $entrada['boe'] ?? null;
        $candidatas = $boe->candidatas($entrada)['candidatas'];
        // La fila en uso se enseña siempre, aunque ya no esté entre las tres más parecidas (p. ej. si la eligió el usuario)
        $idActual = $actual['id'] ?? null;
        if ($idActual !== null && !in_array($idActual, array_map(static fn(array $c): int => $c['fila']['id'], $candidatas), true)) {
            $f = $boe->fila((int) $idActual);
            $e = $f !== null ? $boe->evaluar($entrada, $f) : null;
            if ($e !== null) {
                array_unshift($candidatas, $e);
            }
        }
        $filas = [];
        foreach ($candidatas as $c) {
            $total = null;
            try {
                $r = Calculadora::calcular($boe->aplicar($entrada, $c['fila'], 'usuario'), $tablas);
                $total = $r['completo'] ? (float) $r['total'] : null;
            } catch (EntradaInvalida) {
            }
            $filas[] = $c + ['total' => $total, 'en_uso' => $actual !== null && ($actual['id'] ?? null) === $c['fila']['id']];
        }
        return ['disponible' => true, 'actual' => $actual, 'filas' => $filas, 'anio' => $boe->anio()];
    }

    private function errorCsrf(): Respuesta
    {
        return $this->error(403, 'El formulario ha caducado', 'No hemos podido comprobar que el envío viene de esta página. Vuelve al inicio y rellénalo de nuevo (hace falta que tu navegador acepte la cookie técnica de seguridad).');
    }

    private function error(int $estado, string $titulo, string $mensaje): Respuesta
    {
        return Respuesta::html(Vista::pagina('error', ['estado' => $estado, 'titulo' => $titulo, 'mensaje' => $mensaje], [
            'titulo' => $titulo . ' – Calculadora de importación', 'robots' => 'noindex',
        ]), $estado)->conCabecera('Cache-Control', 'no-store');
    }

    private function tablas(): array
    {
        return $this->tablas ??= Tablas::cargar(Config::dirData(), (int) substr(($this->hoy)(), 0, 4));
    }

    private function almacen(): Almacen
    {
        return $this->almacen ??= new Almacen($this->dirVar);
    }

    private function conCabeceras(Respuesta $r): Respuesta
    {
        $csp = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
            . "connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; manifest-src 'self'";
        $r->cabeceras += [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        return $r;
    }
}
