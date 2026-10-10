<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Anuncio;

/**
 * Saca de un anuncio (HTML descargado, HTML pegado o texto pegado) lo que el analizador necesita:
 *   titulo   el h1, el og:title o el <title>
 *   jsonld   los bloques application/ld+json
 *   pares    rótulo conocido -> valores candidatos (el valor suele estar en el elemento de al lado)
 *   lineas   el texto de la página, una línea por bloque
 *   pistas   textos encontrados con los selectores de data/mobile_de_selectores.json
 * No interpreta nada: eso es el Analizador. Solo lee el documento (nunca ejecuta nada ni carga recursos externos).
 */
final class Extractor
{
    public const MAX_BYTES = 3_145_728;
    private const IGNORADOS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'head', 'object', 'embed'];
    private const BLOQUES = [
        'address', 'article', 'aside', 'blockquote', 'body', 'br', 'dd', 'details', 'dialog', 'div', 'dl', 'dt', 'fieldset', 'figcaption',
        'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section',
        'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /** @param array<string, mixed> $selectores contenido de data/mobile_de_selectores.json */
    public function __construct(private readonly Etiquetas $etiquetas, private readonly array $selectores = [])
    {
    }

    public static function pareceHtml(string $texto): bool
    {
        return preg_match('/<\s*(?:!doctype|html|body|head|div|span|dl|dt|table|tr|li|ul|h1|script)\b/i', $texto) === 1;
    }

    /**
     * @return array{titulo: ?string, jsonld: list<array<string, mixed>>, pares: list<array{campo: string, rotulo: string, valores: list<string>}>, lineas: list<string>, pistas: array<string, list<string>>}
     */
    public function desdeHtml(string $html): array
    {
        $html = substr($html, 0, self::MAX_BYTES);
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        // Ya es UTF-8: se quitan las declaraciones de codificación del documento, que podrían decir otra cosa
        $html = preg_replace('/<meta\b[^>]*\bcharset\b[^>]*>/i', '', $html) ?? $html;
        $doc = new \DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        $xp = new \DOMXPath($doc);

        $jsonld = [];
        foreach ($xp->query("//script[@type='application/ld+json']") ?: [] as $s) {
            $d = json_decode((string) $s->textContent, true);
            if (is_array($d)) {
                foreach (isset($d['@graph']) && is_array($d['@graph']) ? $d['@graph'] : (array_is_list($d) ? $d : [$d]) as $nodo) {
                    if (is_array($nodo)) {
                        $jsonld[] = $nodo;
                    }
                }
            }
        }

        $titulo = $this->titulo($doc, $xp);
        $pistas = $this->pistas($xp);

        $cuerpo = $xp->query('//body')->item(0) ?? $doc->documentElement;
        $pares = $cuerpo !== null ? $this->paresDelArbol($cuerpo) : [];
        $texto = $cuerpo !== null ? $this->texto($cuerpo) : '';
        $lineas = self::lineas($texto);
        return ['titulo' => $titulo, 'jsonld' => $jsonld, 'pares' => $pares, 'lineas' => $lineas, 'pistas' => $pistas];
    }

    /**
     * Texto pegado por el usuario (copiado de la página): rótulo y valor en líneas consecutivas o en la misma línea.
     *
     * @return array{titulo: ?string, jsonld: list<array<string, mixed>>, pares: list<array{campo: string, rotulo: string, valores: list<string>}>, lineas: list<string>, pistas: array<string, list<string>>}
     */
    public function desdeTexto(string $texto): array
    {
        $lineas = self::lineas(substr($texto, 0, self::MAX_BYTES));
        // Sin marcado no hay h1: el título suele ser la primera línea que no es un rótulo ni un precio
        $titulo = null;
        foreach (array_slice($lineas, 0, 12) as $l) {
            if (mb_strlen($l) >= 6 && mb_strlen($l) <= 140 && $this->etiquetas->campo($l) === null && Valores::precio($l) === null && !preg_match('/^\d/', $l)) {
                $titulo = $l;
                break;
            }
        }
        return ['titulo' => $titulo, 'jsonld' => [], 'pares' => $this->paresDeLineas($lineas), 'lineas' => $lineas, 'pistas' => []];
    }

    /** @return list<string> */
    public static function lineas(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r", "\u{00a0}", "\u{200b}"], ["\n", "\n", ' ', ''], $texto);
        $salida = [];
        foreach (explode("\n", $texto) as $l) {
            $l = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]+/u', ' ', $l) ?? '');
            $l = trim(preg_replace('/\s+/u', ' ', $l) ?? '');
            if ($l !== '' && mb_strlen($l) <= 400) {
                $salida[] = $l;
            }
        }
        return $salida;
    }

    // ------------------------------------------------------------------ pares rótulo-valor sobre líneas

    /**
     * @param list<string> $lineas
     * @return list<array{campo: string, rotulo: string, valores: list<string>}>
     */
    public function paresDeLineas(array $lineas): array
    {
        $pares = [];
        $n = count($lineas);
        for ($i = 0; $i < $n; $i++) {
            $l = preg_replace('/^[•·\-–*▪]\s*/u', '', $lineas[$i]) ?? $lineas[$i];
            $campo = $this->etiquetas->campo($l);
            if ($campo !== null) {                       // el rótulo solo en su línea: el valor está en las siguientes
                $valores = [];
                for ($j = $i + 1; $j < min($n, $i + 3); $j++) {
                    if ($this->etiquetas->campo($lineas[$j]) !== null) {
                        break;
                    }
                    $valores[] = $lineas[$j];
                }
                $pares[] = ['campo' => $campo, 'rotulo' => $l, 'valores' => $valores];
                continue;
            }
            if (($pos = strpos($l, ':')) !== false && $pos <= 60) {   // «Rótulo: valor»
                $campo = $this->etiquetas->campo(substr($l, 0, $pos));
                if ($campo !== null) {
                    $valor = trim(substr($l, $pos + 1));
                    $valores = $valor !== '' ? [$valor] : [];
                    if ($valor === '' && isset($lineas[$i + 1]) && $this->etiquetas->campo($lineas[$i + 1]) === null) {
                        $valores[] = $lineas[$i + 1];
                    }
                    $pares[] = ['campo' => $campo, 'rotulo' => substr($l, 0, $pos), 'valores' => $valores];
                    continue;
                }
            }
            $p = $this->etiquetas->prefijo($l);            // «Kilometerstand 105.000 km»
            if ($p !== null) {
                $pares[] = ['campo' => $p['campo'], 'rotulo' => $p['rotulo'], 'valores' => [$p['resto']]];
            }
        }
        return $pares;
    }

    // ------------------------------------------------------------------ árbol HTML

    private function titulo(\DOMDocument $doc, \DOMXPath $xp): ?string
    {
        foreach ($this->selectores['titulo'] ?? [] as $css) {
            $t = $this->textosPorSelector($xp, (string) $css, 1)[0] ?? null;
            if ($t !== null) {
                return $t;
            }
        }
        foreach (["//meta[@property='og:title']/@content", '//h1', '//title'] as $consulta) {
            $n = $xp->query($consulta)->item(0);
            $t = $n !== null ? trim(preg_replace('/\s+/u', ' ', (string) $n->textContent) ?? '') : '';
            if ($t !== '') {
                return mb_substr($t, 0, 200);
            }
        }
        return null;
    }

    /** @return array<string, list<string>> */
    private function pistas(\DOMXPath $xp): array
    {
        $r = [];
        foreach (['precio', 'vendedor', 'ubicacion'] as $clave) {
            $r[$clave] = [];
            foreach ($this->selectores[$clave] ?? [] as $css) {
                array_push($r[$clave], ...$this->textosPorSelector($xp, (string) $css, 5));
            }
        }
        return $r;
    }

    /** @return list<string> */
    private function textosPorSelector(\DOMXPath $xp, string $css, int $max): array
    {
        $x = self::cssAXPath($css);
        if ($x === null) {
            return [];
        }
        $salida = [];
        $nodos = @$xp->query($x);
        foreach ($nodos ?: [] as $n) {
            $t = $n instanceof \DOMAttr ? $n->value : $n->textContent;
            $t = trim(preg_replace('/\s+/u', ' ', (string) $t) ?? '');
            if ($t !== '' && mb_strlen($t) <= 300) {
                $salida[] = $t;
                if (count($salida) >= $max) {
                    break;
                }
            }
        }
        return $salida;
    }

    /**
     * Traduce los selectores sencillos del fichero de selectores (etiqueta, [atr], [atr='v'], [atr*='v'], [atr^='v'], [atr$='v'],
     * .clase, #id, espacio y «>») a XPath. Devuelve null si el selector usa algo más complejo (entonces se ignora).
     */
    public static function cssAXPath(string $css): ?string
    {
        $css = trim($css);
        $sinTextos = preg_replace('/(["\']).*?\1/', '""', $css) ?? '';
        if ($css === '' || strlen($css) > 200 || !preg_match('/^[\w\s>\[\]=*^$.#:\'"\-~|]+$/', $css) || str_contains($sinTextos, ':')) {
            return null;
        }
        $css = preg_replace('/\s*>\s*/', ' > ', $css) ?? $css;
        $partes = preg_split('/\s+/', $css) ?: [];
        $x = '';
        $siguiente = '//';
        $ultimoTag = '';
        foreach ($partes as $p) {
            if ($p === '>') {
                $siguiente = '/';
                continue;
            }
            if (!preg_match('/^(\*|[a-zA-Z][\w-]*)?((?:\[[^\]]+\]|\.[\w-]+|#[\w-]+)*)$/', $p, $m)) {
                return null;
            }
            $cond = [];
            if (preg_match_all('/\[([\w:-]+)(?:([*^$]?=)(?:"([^"]*)"|\'([^\']*)\'|([\w.-]*)))?\]|\.([\w-]+)|#([\w-]+)/', $m[2], $mm, PREG_SET_ORDER)) {
                foreach ($mm as $c) {
                    if (($c[6] ?? '') !== '') {
                        $cond[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . $c[6] . " ')";
                    } elseif (($c[7] ?? '') !== '') {
                        $cond[] = "@id='" . $c[7] . "'";
                    } elseif (($c[2] ?? '') === '') {
                        $cond[] = '@' . $c[1];
                    } else {
                        $v = ($c[3] ?? '') !== '' ? $c[3] : (($c[4] ?? '') !== '' ? $c[4] : ($c[5] ?? ''));
                        if (str_contains($v, "'")) {
                            return null;
                        }
                        $cond[] = match ($c[2]) {
                            '=' => "@{$c[1]}='$v'",
                            '*=' => "contains(@{$c[1]},'$v')",
                            '^=' => "starts-with(@{$c[1]},'$v')",
                            '$=' => "substring(@{$c[1]}, string-length(@{$c[1]}) - " . (strlen($v) - 1) . ") = '$v'",
                        };
                    }
                }
            }
            $tag = ($m[1] ?? '') !== '' ? $m[1] : '*';
            $ultimoTag = $tag;
            $x .= $siguiente . $tag . ($cond ? '[' . implode(' and ', $cond) . ']' : '');
            $siguiente = '//';
        }
        // De un <meta> interesa su atributo content, no su texto (que está vacío)
        return $ultimoTag === 'meta' ? $x . '/@content' : $x;
    }

    /** Texto del árbol con un salto de línea alrededor de cada bloque. */
    private function texto(\DOMNode $nodo): string
    {
        $salida = '';
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo instanceof \DOMText) {
                $salida .= $hijo->nodeValue;
            } elseif ($hijo instanceof \DOMElement) {
                $t = strtolower($hijo->nodeName);
                if (in_array($t, self::IGNORADOS, true) || $hijo->hasAttribute('hidden')) {
                    continue;
                }
                $bloque = in_array($t, self::BLOQUES, true);
                $salida .= ($bloque ? "\n" : '') . $this->texto($hijo) . ($bloque ? "\n" : '');
            }
        }
        return $salida;
    }

    /**
     * @return list<array{campo: string, rotulo: string, valores: list<string>}>
     */
    private function paresDelArbol(\DOMNode $raiz): array
    {
        $pares = [];
        $xp = new \DOMXPath($raiz->ownerDocument);
        foreach ($xp->query('.//*[not(ancestor-or-self::script or ancestor-or-self::style or ancestor-or-self::noscript or ancestor-or-self::template)]', $raiz) ?: [] as $e) {
            /** @var \DOMElement $e */
            if ($e->hasAttribute('hidden') || $this->tieneBloquesDentro($e)) {
                continue;
            }
            $texto = $this->visible($e);
            if ($texto === '' || mb_strlen($texto) > 200) {
                continue;
            }
            // «Rótulo: valor» en un solo elemento
            if (($pos = strpos($texto, ':')) !== false && $pos <= 60 && ($campo = $this->etiquetas->campo(substr($texto, 0, $pos))) !== null) {
                $valor = trim(substr($texto, $pos + 1));
                if ($valor !== '') {
                    $pares[] = ['campo' => $campo, 'rotulo' => substr($texto, 0, $pos), 'valores' => [$valor]];
                    continue;
                }
            }
            $campo = $this->etiquetas->campo(rtrim($texto, ': '));
            if ($campo === null) {
                continue;
            }
            // Solo el elemento más interno con ese texto (el <div> que envuelve a un <span> igual no cuenta)
            $unicoHijo = $this->unicoHijoElemento($e);
            if ($unicoHijo !== null && $this->visible($unicoHijo) === $texto) {
                continue;
            }
            $valores = $this->valoresDe($e, $texto);
            if ($valores) {
                $pares[] = ['campo' => $campo, 'rotulo' => $texto, 'valores' => $valores];
            }
        }
        return $pares;
    }

    /** @return list<string> */
    private function valoresDe(\DOMElement $e, string $rotulo): array
    {
        $valores = [];
        $candidato = function (?\DOMElement $n) use (&$valores): void {
            if ($n !== null) {
                $t = $this->visible($n);
                if ($t !== '' && mb_strlen($t) <= 200) {
                    $valores[] = $t;
                }
            }
        };
        // 1. El elemento de al lado: <dt>/<dd>, <th>/<td>, <div>/<div>…
        $candidato($this->siguienteElemento($e));
        // 2. «<li><span>Rótulo</span> valor</li>»: el resto del texto del padre
        $padre = $e->parentNode;
        if ($padre instanceof \DOMElement) {
            $t = $this->visible($padre);
            if ($t !== $rotulo && str_starts_with($t, $rotulo) && mb_strlen($t) <= 200) {
                $valores[] = trim(ltrim(substr($t, strlen($rotulo)), ': '));
            }
        }
        // 3. El rótulo va envuelto (<div><p><b>Rótulo</b></p></div><div><p>valor</p></div>): se sube hasta el envoltorio que tiene hermano
        if (!$valores) {
            $nodo = $e;
            for ($i = 0; $i < 4 && $nodo->parentNode instanceof \DOMElement; $i++) {
                if ($this->siguienteElemento($nodo) !== null) {
                    $candidato($this->siguienteElemento($nodo));
                    break;
                }
                if ($this->contarHijosElemento($nodo->parentNode) !== 1) {
                    break;
                }
                $nodo = $nodo->parentNode;
            }
        }
        return array_values(array_unique(array_filter($valores, static fn(string $v): bool => $v !== '')));
    }

    private function limpiar(string $t): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00a0}", ' ', $t)) ?? '');
    }

    /** Texto de un nodo sin las notas al pie (<sup>, <sub>) ni lo que no se ve. */
    private function visible(\DOMNode $n): string
    {
        $t = '';
        foreach ($n->childNodes as $h) {
            if ($h instanceof \DOMText) {
                $t .= $h->nodeValue;
            } elseif ($h instanceof \DOMElement && !in_array(strtolower($h->nodeName), [...self::IGNORADOS, 'sup', 'sub'], true) && !$h->hasAttribute('hidden')) {
                $t .= ' ' . $this->visible($h) . ' ';
            }
        }
        return $this->limpiar($t);
    }

    private function tieneBloquesDentro(\DOMElement $e): bool
    {
        foreach ($e->getElementsByTagName('*') as $h) {
            if (in_array(strtolower($h->nodeName), self::BLOQUES, true)) {
                return true;
            }
        }
        return false;
    }

    private function unicoHijoElemento(\DOMElement $e): ?\DOMElement
    {
        $uno = null;
        foreach ($e->childNodes as $h) {
            if ($h instanceof \DOMElement) {
                if ($uno !== null) {
                    return null;
                }
                $uno = $h;
            } elseif ($h instanceof \DOMText && trim($h->nodeValue ?? '') !== '') {
                return null;
            }
        }
        return $uno;
    }

    private function contarHijosElemento(\DOMElement $e): int
    {
        $n = 0;
        foreach ($e->childNodes as $h) {
            $n += $h instanceof \DOMElement ? 1 : 0;
        }
        return $n;
    }

    private function siguienteElemento(\DOMNode $n): ?\DOMElement
    {
        for ($s = $n->nextSibling; $s !== null; $s = $s->nextSibling) {
            if ($s instanceof \DOMElement && !in_array(strtolower($s->nodeName), self::IGNORADOS, true)) {
                return $s;
            }
        }
        return null;
    }
}
