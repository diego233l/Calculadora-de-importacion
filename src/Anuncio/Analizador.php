<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Anuncio;

use CalculadoraImportacion\Boe\Marcas;

/**
 * Convierte lo que el Extractor ha leído de un anuncio en los campos del formulario, cada uno con su confianza (0 a 1)
 * y su origen. Orden de fiabilidad:
 *   estructurado (JSON-LD) 0,95 · rótulo conocido 0,90 · título del anuncio 0,55 a 0,85 · expresión regular 0,50 a 0,60
 * Si dos fuentes dan valores distintos para un dato, se queda con la mejor y baja su confianza. Lo que no se encuentra
 * no se rellena: nunca se inventa un valor. Los campos con confianza < UMBRAL_SEGURO se marcan para que el usuario los revise.
 */
final class Analizador
{
    public const UMBRAL_SEGURO = 0.8;
    /** Campos del formulario que se rellenan y que hacen falta para calcular. */
    public const CAMPOS = ['marca', 'modelo', 'version', 'matriculacion', 'km', 'combustible', 'cc', 'kw', 'cv', 'co2', 'precio', 'vendedor', 'iva_deducible', 'cp_vendedor'];
    public const OBLIGATORIOS = ['marca', 'modelo', 'matriculacion', 'km', 'combustible', 'precio', 'vendedor', 'cp_vendedor'];
    private const PRECIO_NO_VALE = '/mtl|monat|rate\b|raten|ab\s+\d|anzahlung|finanz|leasing|netto|zzgl|zuzüglich|uvp|statt|vorher|ersparnis|kosten|\bpro\b|\/\s*mon/iu';

    private function __construct(
        private readonly Etiquetas $etiquetas,
        private readonly Extractor $extractor,
        private readonly Marcas $marcas,
        private readonly Modelos $modelos,
    ) {
    }

    public static function crear(string $dirData): self
    {
        $etiquetas = Etiquetas::cargar($dirData);
        $t = @file_get_contents($dirData . '/mobile_de_selectores.json');
        $selectores = $t !== false ? json_decode($t, true) : null;
        return new self($etiquetas, new Extractor($etiquetas, is_array($selectores) ? $selectores : []), Marcas::cargar($dirData), new Modelos($dirData));
    }

    public function analizarHtml(string $html, ?string $hoy = null): array
    {
        return $this->analizar($this->extractor->desdeHtml($html), 'html', $hoy);
    }

    /** Texto copiado de la página (o, si lo pegado parece HTML, el código fuente). */
    public function analizarTexto(string $texto, ?string $hoy = null): array
    {
        if (Extractor::pareceHtml($texto)) {
            return $this->analizar($this->extractor->desdeHtml($texto), 'html', $hoy);
        }
        return $this->analizar($this->extractor->desdeTexto($texto), 'texto', $hoy);
    }

    /**
     * @param array<string, mixed> $ex resultado del Extractor
     * @return array{
     *   fuente: string, titulo: ?string,
     *   campos: array<string, array{valor: string, confianza: float, origen: string, nota: ?string}>,
     *   dudosos: list<string>, faltan: list<string>, avisos: list<string>, desconocidos: list<string>, extras: array<string, string>
     * }
     */
    private function analizar(array $ex, string $fuente, ?string $hoy): array
    {
        $hoy ??= date('Y-m-d');
        $c = new Candidatos();
        $desconocidos = [];
        $extras = [];
        $avisos = [];
        $lineas = $ex['lineas'];

        $this->desdeJsonLd($ex['jsonld'], $c);
        $this->desdePares($ex['pares'], $c, $desconocidos, $extras, $avisos);
        $this->vendedorYPrecio($ex, $c, $fuente);
        $titulo = $this->limpiarTitulo($ex['titulo'] ?? null, $ex['jsonld']);
        $this->desdeTitulo($titulo, $c, $desconocidos);
        $this->respaldoConRegex($lineas, $c);

        // IVA desglosable: solo cuenta si el anuncio lo dice; que no lo diga no significa que no lo sea
        if ($this->etiquetas->mencionaIvaDeducible(implode("\n", $lineas))) {
            $c->proponer('iva_deducible', '1', 0.85, 'etiqueta');
        }

        $campos = [];
        foreach (self::CAMPOS as $campo) {
            $mejor = $c->mejor($campo);
            if ($mejor === null) {
                continue;
            }
            if ($campo === 'matriculacion' && preg_match('#^(\d{2})/(\d{4})$#', $mejor['valor'], $mm) && $mm[2] . '-' . $mm[1] . '-01' > $hoy) {
                $avisos[] = 'La fecha de matriculación que aparece en el anuncio es posterior a hoy: revísala.';
                $mejor['confianza'] = min($mejor['confianza'], 0.4);
            }
            $campos[$campo] = $mejor;
        }

        // Potencia: si solo se ha leído una de las dos unidades, se calcula la otra
        if (isset($campos['kw']) && !isset($campos['cv'])) {
            $cv = (int) round(((float) str_replace(',', '.', $campos['kw']['valor'])) * 1.35962);
            $campos['cv'] = ['valor' => (string) $cv, 'confianza' => $campos['kw']['confianza'], 'origen' => 'derivado', 'nota' => null];
        } elseif (isset($campos['cv']) && !isset($campos['kw'])) {
            $kw = round(((int) $campos['cv']['valor']) / 1.35962, 1);
            $campos['kw'] = ['valor' => self::decimal($kw), 'confianza' => $campos['cv']['confianza'], 'origen' => 'derivado', 'nota' => null];
        }
        // Un eléctrico no tiene cilindrada
        if (($campos['combustible']['valor'] ?? '') === 'electrico') {
            unset($campos['cc'], $campos['co2']);
        }
        if (isset($campos['vendedor']) && $campos['vendedor']['valor'] === 'particular') {
            unset($campos['iva_deducible']);
        }

        $pais = $c->extra('pais_vendedor');
        if ($pais !== null && $pais !== 'DE') {
            $avisos[] = "El vendedor parece estar en otro país ($pais). La calculadora solo sabe calcular el transporte desde códigos postales alemanes: revisa el código postal.";
        }
        if (($campos['matriculacion']['nota'] ?? null) === 'solo_anio') {
            $avisos[] = 'El anuncio solo indica el año de la primera matriculación. Pon el mes exacto (aparece en la ficha técnica del anuncio): cambia el cálculo de la antigüedad y del IVA si es un coche casi nuevo.';
            $campos['matriculacion']['nota'] = 'Solo hemos podido leer el año; he puesto enero. Corrige el mes.';
        }

        $dudosos = array_keys(array_filter($campos, static fn(array $f): bool => $f['confianza'] < self::UMBRAL_SEGURO));
        $faltan = array_values(array_filter(self::OBLIGATORIOS, static fn(string $f): bool => !isset($campos[$f])));
        if (!isset($campos['cc']) && ($campos['combustible']['valor'] ?? '') !== 'electrico') {
            $faltan[] = 'cc';
        }
        return [
            'fuente' => $fuente, 'titulo' => $titulo, 'campos' => $campos, 'dudosos' => $dudosos, 'faltan' => $faltan,
            'avisos' => $avisos, 'desconocidos' => array_values(array_unique($desconocidos)), 'extras' => $extras,
        ];
    }

    // ------------------------------------------------------------------ fuentes

    /** @param list<array<string, mixed>> $nodos */
    private function desdeJsonLd(array $nodos, Candidatos $c): void
    {
        foreach ($nodos as $n) {
            $tipos = array_map('strval', (array) ($n['@type'] ?? []));
            if (!array_intersect($tipos, ['Car', 'Vehicle', 'MotorizedVehicle', 'Product', 'Offer', 'UsedCar'])) {
                continue;
            }
            $texto = static fn(mixed $v): ?string => is_string($v) ? $v : (is_array($v) && isset($v['name']) && is_string($v['name']) ? $v['name'] : null);
            if (($m = $texto($n['brand'] ?? $n['manufacturer'] ?? null)) !== null && ($boe = $this->marcas->resolver($m)) !== null) {
                $c->proponer('marca', $this->marcas->nombreVisible($boe), 0.95, 'estructurado');
            }
            if (($m = $texto($n['model'] ?? null)) !== null) {
                $c->proponer('modelo', Valores::textoLibre($m), 0.95, 'estructurado');
            }
            $km = $n['mileageFromOdometer'] ?? null;
            $km = is_array($km) ? ($km['value'] ?? null) : $km;
            if (($km !== null && is_scalar($km)) && ($v = Valores::kilometros((string) $km)) !== null) {
                $c->proponer('km', (string) $v, 0.95, 'estructurado');
            }
            if (is_string($n['fuelType'] ?? null) && ($f = $this->etiquetas->combustible($n['fuelType'])) !== null) {
                $c->proponer('combustible', $f['clave'], $f['exacto'] && $f['clave'] !== 'hibrido' ? 0.95 : 0.75, 'estructurado');
            }
            $motor = $n['vehicleEngine'] ?? null;
            if (is_array($motor)) {
                $p = $motor['enginePower'] ?? null;
                if (is_array($p) && isset($p['value']) && is_scalar($p['value'])) {
                    $unidad = strtoupper((string) ($p['unitCode'] ?? $p['unitText'] ?? ''));
                    $t = (string) $p['value'] . ' ' . (in_array($unidad, ['KWT', 'KW'], true) ? 'kW' : 'PS');
                    $this->potencia(Valores::potencia($t), 0.95, 'estructurado', $c);
                }
                $d = $motor['engineDisplacement'] ?? null;
                if (is_array($d) && isset($d['value']) && is_scalar($d['value'])) {
                    $litros = in_array(strtoupper((string) ($d['unitCode'] ?? '')), ['LTR', 'L'], true);
                    $v = Valores::cilindrada((string) $d['value'] . ($litros ? ' l' : ' cm3'));
                    if ($v !== null) {
                        $c->proponer('cc', (string) $v, 0.95, 'estructurado');
                    }
                }
            }
            foreach (['dateVehicleFirstRegistered', 'productionDate', 'vehicleModelDate'] as $clave) {
                if (is_string($n[$clave] ?? null) && ($f = Valores::matriculacion($n[$clave])) !== null) {
                    $this->fecha($f, $clave === 'vehicleModelDate' ? 0.6 : 0.95, 'estructurado', $c);
                    break;
                }
            }
            $ofertas = $n['offers'] ?? ($tipos === ['Offer'] ? $n : null);
            foreach (is_array($ofertas) ? (array_is_list($ofertas) ? $ofertas : [$ofertas]) : [] as $o) {
                if (!is_array($o)) {
                    continue;
                }
                if (isset($o['price']) && is_scalar($o['price']) && ($o['priceCurrency'] ?? 'EUR') === 'EUR') {
                    $v = Valores::precio(((string) $o['price']) . ' €') ?? Valores::precio(str_replace('.', ',', (string) $o['price']) . ' €');
                    if ($v !== null) {
                        $c->proponer('precio', self::decimal($v), 0.95, 'estructurado');
                    }
                }
                $tipoVendedor = is_array($o['seller'] ?? null) ? (string) ($o['seller']['@type'] ?? '') : '';
                if ($tipoVendedor !== '') {
                    $c->proponer('vendedor', $tipoVendedor === 'Person' ? 'particular' : 'profesional', 0.9, 'estructurado');
                }
                $dir = $o['availableAtOrFrom']['address'] ?? $o['seller']['address'] ?? null;
                if (is_array($dir) && isset($dir['postalCode']) && is_scalar($dir['postalCode'])) {
                    $this->ubicacion(Valores::ubicacion((string) ($dir['addressCountry'] ?? 'DE') === 'DE' ? 'DE-' . $dir['postalCode'] : (string) $dir['postalCode']), 0.9, 'estructurado', $c);
                }
            }
        }
    }

    /**
     * @param list<array{campo: string, rotulo: string, valores: list<string>}> $pares
     * @param list<string> $desconocidos
     * @param array<string, string> $extras
     * @param list<string> $avisos
     */
    private function desdePares(array $pares, Candidatos $c, array &$desconocidos, array &$extras, array &$avisos): void
    {
        foreach ($pares as $p) {
            $valores = $p['valores'];
            if ($p['campo'] === 'precio') {
                // Un rótulo «Brutto» suelto no debe tomar como suyo el precio neto ni el de la financiación de la línea siguiente
                $valores = array_values(array_filter($valores, static fn(string $x): bool => !preg_match(self::PRECIO_NO_VALE, $x)));
            }
            $primero = static function (callable $f) use ($valores): mixed {
                foreach ($valores as $v) {
                    $r = $f($v);
                    if ($r !== null) {
                        return $r;
                    }
                }
                return null;
            };
            switch ($p['campo']) {
                case 'km':
                    if (($v = $primero(Valores::kilometros(...))) !== null) {
                        $c->proponer('km', (string) $v, 0.9, 'etiqueta');
                    }
                    break;
                case 'matriculacion':
                    if (($v = $primero(Valores::matriculacion(...))) !== null) {
                        $this->fecha($v, 0.9, 'etiqueta', $c);
                    }
                    break;
                case 'potencia':
                    $this->potencia($primero(Valores::potencia(...)), 0.9, 'etiqueta', $c);
                    break;
                case 'cilindrada':
                    if (($v = $primero(Valores::cilindrada(...))) !== null) {
                        $c->proponer('cc', (string) $v, 0.9, 'etiqueta');
                    }
                    break;
                case 'combustible':
                    $f = $primero($this->etiquetas->combustible(...));
                    if ($f !== null) {
                        $nota = $f['clave'] === 'hibrido' ? 'El anuncio dice «híbrido»: comprueba si es enchufable (PHEV), porque cambia el cálculo.' : null;
                        $c->proponer('combustible', $f['clave'], $f['exacto'] && $f['clave'] !== 'hibrido' ? 0.9 : 0.75, 'etiqueta', $nota);
                    } elseif ($valores !== []) {
                        $desconocidos[] = 'combustible: «' . mb_substr($valores[0], 0, 60) . '»';
                    }
                    break;
                case 'co2':
                    if (($v = $primero(Valores::co2(...))) !== null) {
                        $c->proponer('co2', self::decimal($v), 0.9, 'etiqueta');
                    }
                    break;
                case 'ubicacion':
                    $this->ubicacion($primero(Valores::ubicacion(...)), 0.9, 'etiqueta', $c);
                    break;
                case 'precio':
                    if (($v = $primero(Valores::precio(...))) !== null) {
                        $c->proponer('precio', self::decimal($v), 0.85, 'etiqueta');
                    }
                    break;
                case 'precio_neto':
                    if ($primero(Valores::precio(...)) !== null) {
                        // mobile.de solo enseña el precio neto cuando el IVA se puede desglosar
                        $c->proponer('iva_deducible', '1', 0.7, 'etiqueta', 'Hay precio neto en el anuncio: el IVA parece desglosable.');
                    }
                    break;
                case 'marca':
                    if (isset($valores[0]) && ($boe = $this->marcas->resolver($valores[0])) !== null) {
                        $c->proponer('marca', $this->marcas->nombreVisible($boe), 0.9, 'etiqueta');
                    } elseif (isset($valores[0])) {
                        $desconocidos[] = 'marca: «' . mb_substr($valores[0], 0, 60) . '»';
                    }
                    break;
                case 'modelo':
                    if (isset($valores[0]) && mb_strlen($valores[0]) <= 60) {
                        $c->proponer('modelo', Valores::textoLibre($valores[0]), 0.9, 'etiqueta');
                    }
                    break;
                case 'version':
                    if (isset($valores[0]) && mb_strlen($valores[0]) <= 100) {
                        $c->proponer('version', Valores::textoLibre($valores[0]), 0.85, 'etiqueta');
                    }
                    break;
                case 'vendedor':
                    if (($v = $primero($this->etiquetas->vendedor(...))) !== null) {
                        $c->proponer('vendedor', $v, 0.9, 'etiqueta');
                    }
                    break;
                case 'cambio':
                case 'estado':
                case 'propietarios':
                    if (isset($valores[0])) {
                        $extras[$p['campo']] = Valores::textoLibre($valores[0], 60);
                    }
                    break;
            }
        }
    }

    /** Tipo de vendedor y precio cuando no vienen con rótulo: pistas de selectores y líneas sueltas. */
    private function vendedorYPrecio(array $ex, Candidatos $c, string $fuente): void
    {
        foreach ($ex['pistas']['vendedor'] ?? [] as $t) {
            if (($v = $this->etiquetas->vendedor($t)) !== null) {
                $c->proponer('vendedor', $v, 0.8, 'etiqueta');
            }
        }
        // Una línea o un elemento que sea solo «Privatanbieter» o «Händler» (nunca una frase: «Händler-Suche» está en cualquier menú)
        foreach ($ex['lineas'] as $l) {
            if (($v = $this->etiquetas->vendedorEnLinea($l)) !== null) {
                $c->proponer('vendedor', $v, 0.8, 'etiqueta');
            }
        }
        foreach ($ex['pistas']['precio'] ?? [] as $t) {
            if (($v = Valores::precio($t)) !== null && !preg_match(self::PRECIO_NO_VALE, $t)) {
                $c->proponer('precio', self::decimal($v), 0.8, 'etiqueta');
            }
        }
        foreach ($ex['pistas']['ubicacion'] ?? [] as $t) {
            $this->ubicacion(Valores::ubicacion($t), 0.8, 'etiqueta', $c);
        }
        $lineas = $ex['lineas'];
        foreach ($lineas as $i => $l) {
            if (mb_strlen($l) > 60 || preg_match(self::PRECIO_NO_VALE, $l) || ($v = Valores::precio($l)) === null) {
                continue;
            }
            // El precio con «Brutto» al lado es el del anuncio; sin él, el primero que aparece
            $contexto = $l . ' ' . ($lineas[$i + 1] ?? '') . ' ' . ($lineas[$i - 1] ?? '');
            $c->proponer('precio', self::decimal($v), preg_match('/brutto|gross|bruto/i', $contexto) ? 0.8 : 0.6, 'regex');
        }
    }

    private function desdeTitulo(?string $titulo, Candidatos $c, array &$desconocidos): void
    {
        if ($titulo === null) {
            return;
        }
        $palabras = preg_split('/\s+/u', $titulo, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $boe = null;
        $usadas = 0;
        for ($n = min(3, count($palabras)); $n >= 1; $n--) {
            $r = $this->marcas->resolver(implode(' ', array_slice($palabras, 0, $n)));
            if ($r !== null) {
                [$boe, $usadas] = [$r, $n];
                break;
            }
        }
        if ($boe === null) {
            if ($palabras !== []) {
                $desconocidos[] = 'marca en el título: «' . mb_substr($palabras[0], 0, 40) . '»';
            }
            return;
        }
        $c->proponer('marca', $this->marcas->nombreVisible($boe), 0.85, 'título');
        $resto = array_slice($palabras, $usadas);
        if ($resto === []) {
            return;
        }
        $m = $this->modelos->reconocer($boe, $resto);
        if ($m !== null) {
            $c->proponer('modelo', Valores::textoLibre(implode(' ', array_slice($resto, 0, $m['n']))), $m['exacto'] ? 0.8 : 0.7, 'título');
            $version = array_slice($resto, $m['n']);
        } else {
            $c->proponer('modelo', Valores::textoLibre($resto[0]), 0.55, 'título', 'No hemos reconocido el modelo en nuestro diccionario: hemos tomado la primera palabra del título.');
            $version = array_slice($resto, 1);
        }
        if ($version !== []) {
            $c->proponer('version', Valores::textoLibre(implode(' ', $version)), 0.6, 'título', 'Es el resto del título del anuncio; puede incluir extras del vendedor.');
        }
    }

    /** @param list<string> $lineas */
    private function respaldoConRegex(array $lineas, Candidatos $c): void
    {
        $texto = implode("\n", $lineas);
        if (preg_match('/(?<![\d.,])(\d{1,3}(?:[.,\x{00a0} ]\d{3})+|\d{3,7})\s*km\b(?!\s*\/)/iu', $texto, $m) && ($v = Valores::kilometros($m[0])) !== null) {
            $c->proponer('km', (string) $v, 0.6, 'regex');
        }
        if (preg_match('/(?:EZ|Erstzulassung|Zulassung)\s*:?\s*(\d{1,2}\s*[\/.]\s*(?:19|20)\d{2})/iu', $texto, $m) && ($v = Valores::matriculacion($m[1])) !== null) {
            $this->fecha($v, 0.6, 'regex', $c);
        } elseif (preg_match('#(?<![\d/.])(0[1-9]|1[0-2])/((?:19|20)\d{2})(?![\d/])#', $texto, $m)) {
            $this->fecha(['anio' => (int) $m[2], 'mes' => (int) $m[1]], 0.5, 'regex', $c);
        }
        if (preg_match('/(?<![\d.,])\d{1,4}(?:[.,]\d{1,2})?\s*kW\b.{0,25}|\d{2,4}\s*PS\b.{0,25}/iu', $texto, $m)) {
            $this->potencia(Valores::potencia($m[0]), 0.6, 'regex', $c);
        }
        if (preg_match('/(?<![\d.,])(\d{2,3}(?:[.,]\d)?)\s*g\s*\/\s*km/iu', $texto, $m) && ($v = Valores::co2($m[1])) !== null) {
            $c->proponer('co2', self::decimal($v), 0.6, 'regex');
        }
        if (preg_match('/(?<![\d.,])(\d[.,\x{00a0} ]?\d{3})\s*(?:ccm|cm³|cm3)\b/iu', $texto, $m) && ($v = Valores::cilindrada($m[0])) !== null) {
            $c->proponer('cc', (string) $v, 0.6, 'regex');
        }
        // Código postal: solo con el prefijo de país, que no se confunde con un precio o un kilometraje.
        // Una línea que es solo «DE-80331 München» es el rótulo de ubicación del vendedor.
        foreach ($lineas as $l) {
            if (preg_match('/^(?:DE|D)\s*-\s*\d{5}\s+\p{L}[\p{L} .\'\/-]{1,40}$/u', $l)) {
                $this->ubicacion(Valores::ubicacion($l), 0.8, 'regex', $c);
            }
        }
        if (preg_match('/\b(?:DE|D)\s*-\s*\d{5}\s+[^\d,\n|]{2,40}/u', $texto, $m)) {
            $this->ubicacion(Valores::ubicacion($m[0]), 0.7, 'regex', $c);
        }
    }

    // ------------------------------------------------------------------ piezas

    /** @param array{kw?: float, cv?: int}|null $p */
    private function potencia(?array $p, float $conf, string $origen, Candidatos $c): void
    {
        if ($p === null) {
            return;
        }
        if (isset($p['kw'])) {
            $c->proponer('kw', self::decimal($p['kw']), $conf, $origen);
        }
        if (isset($p['cv'])) {
            $c->proponer('cv', (string) $p['cv'], $conf, $origen);
        }
    }

    /** @param array{anio: int, mes: ?int}|null $f */
    private function fecha(?array $f, float $conf, string $origen, Candidatos $c): void
    {
        if ($f === null) {
            return;
        }
        if ($f['mes'] === null) {
            $c->proponer('matriculacion', sprintf('01/%04d', $f['anio']), min($conf, 0.5), $origen, 'solo_anio');
            return;
        }
        $c->proponer('matriculacion', sprintf('%02d/%04d', $f['mes'], $f['anio']), $conf, $origen);
    }

    /** @param array{plz: string, ciudad: ?string, pais: string}|null $u */
    private function ubicacion(?array $u, float $conf, string $origen, Candidatos $c): void
    {
        if ($u === null) {
            return;
        }
        $c->extraer('pais_vendedor', $u['pais']);
        if ($u['pais'] === 'DE') {
            $c->proponer('cp_vendedor', $u['plz'], $conf, $origen);
        } else {
            $c->proponer('cp_vendedor', $u['plz'], min($conf, 0.3), $origen, 'No es un código postal alemán.');
        }
    }

    /** @param list<array<string, mixed>> $jsonld */
    private function limpiarTitulo(?string $titulo, array $jsonld): ?string
    {
        if ($titulo === null) {
            foreach ($jsonld as $n) {
                if (is_string($n['name'] ?? null)) {
                    $titulo = $n['name'];
                    break;
                }
            }
        }
        if ($titulo === null) {
            return null;
        }
        $t = Valores::textoLibre($titulo, 200);
        $t = preg_replace('/\s*[-–|·]\s*(?:mobile\.de|Gebrauchtwagen.*|Angebot.*|zu verkaufen.*)$/iu', '', $t) ?? $t;
        $t = preg_replace('/\s*[-–|]\s*\d[\d.,]*\s*€.*$/u', '', $t) ?? $t;
        $t = trim($t);
        return $t !== '' ? $t : null;
    }

    private static function decimal(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }
}
