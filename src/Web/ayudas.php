<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/*
 * Atajos para las plantillas (src/Vistas): se resuelven como funciones del espacio de nombres Web.
 */

function e(mixed $valor): string
{
    return Vista::e($valor);
}

function eur(?float $valor): string
{
    return Vista::eur($valor);
}

function num(float $valor, int $decimales = 0): string
{
    return Vista::num($valor, $decimales);
}

function pct(float $valor): string
{
    return Vista::pct($valor);
}

function fecha(string $iso): string
{
    return Vista::fecha($iso);
}

/**
 * Campo de texto accesible: etiqueta enlazada, pista y error asociados con aria-describedby.
 * @param array{pista?: string, modo?: string, auto?: string, obligatorio?: bool, ancho?: string} $op
 */
function campoTexto(string $nombre, string $etiqueta, array $valores, array $errores, array $op = []): string
{
    $id = 'f-' . $nombre;
    $valor = (string) ($valores[$nombre] ?? '');
    $error = $errores[$nombre] ?? null;
    $describe = [];
    $html = '<div class="campo"><label for="' . e($id) . '">' . e($etiqueta)
        . (empty($op['obligatorio']) ? ' <span class="opcional">(opcional)</span>' : '') . '</label>';
    if (!empty($op['pista'])) {
        $html .= '<p class="pista" id="p-' . e($nombre) . '">' . e($op['pista']) . '</p>';
        $describe[] = 'p-' . $nombre;
    }
    if ($error !== null) {
        $describe[] = 'e-' . $nombre;
    }
    $html .= '<input type="text" id="' . e($id) . '" name="' . e($nombre) . '" value="' . e($valor) . '"'
        . ' inputmode="' . e($op['modo'] ?? 'text') . '" autocomplete="' . e($op['auto'] ?? 'off') . '" spellcheck="false"'
        . ($describe ? ' aria-describedby="' . e(implode(' ', $describe)) . '"' : '')
        . ($error !== null ? ' aria-invalid="true"' : '')
        . (!empty($op['obligatorio']) ? ' aria-required="true"' : '') . '>';
    if ($error !== null) {
        $html .= '<p class="error-campo" id="e-' . e($nombre) . '">' . e($error) . '</p>';
    }
    return $html . '</div>';
}

/** Selector de municipio de destino: con JavaScript es un autocompletado; sin él, un campo de texto que el servidor resuelve. */
function campoMunicipio(array $valores, array $errores, array $candidatos): string
{
    $error = $errores['municipio'] ?? null;
    $describe = 'p-municipio' . ($error !== null ? ' e-municipio' : '');
    $html = '<div class="campo"><label for="f-municipio_texto">Municipio donde matricularás el coche</label>'
        . '<p class="pista" id="p-municipio">Escribe las primeras letras y elígelo de la lista. Cuenta el del domicilio del titular.</p>'
        . '<div class="combo" data-combo="municipios">'
        . '<input type="text" id="f-municipio_texto" name="municipio_texto" value="' . e($valores['municipio_texto'] ?? '') . '"'
        . ' autocomplete="off" spellcheck="false" aria-required="true" aria-describedby="' . e($describe) . '"'
        . ($error !== null ? ' aria-invalid="true"' : '') . '>'
        . '<input type="hidden" id="f-municipio" name="municipio" value="' . e($valores['municipio'] ?? '') . '">'
        . '</div>';
    if ($error !== null) {
        $html .= '<p class="error-campo" id="e-municipio">' . e($error) . '</p>';
    }
    if ($candidatos) {
        $html .= '<div class="sugerencias" role="group" aria-label="Municipios posibles">';
        foreach ($candidatos as $c) {
            $html .= '<button class="boton boton--secundario boton--pequeno" type="submit" name="municipio" value="' . e($c['ine']) . '">' . e($c['etiqueta']) . '</button>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/** Resumen de errores al principio del formulario, con enlaces a cada campo. */
function resumenErrores(array $errores, array $etiquetas): string
{
    if (!$errores) {
        return '';
    }
    $html = '<div class="resumen-errores" role="alert" tabindex="-1" data-resumen-errores><h2>Revisa estos datos</h2><ul>';
    foreach ($errores as $campo => $texto) {
        $destino = $campo === 'general' ? '' : ($campo === 'municipio' ? '#f-municipio_texto' : '#f-' . $campo);
        $nombre = $etiquetas[$campo] ?? '';
        $html .= '<li>' . ($destino !== '' ? '<a href="' . e($destino) . '">' . e($nombre) . '</a>: ' : '') . e($texto) . '</li>';
    }
    return $html . '</ul></div>';
}
