<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Anuncio;

/**
 * Descarga de una página de anuncio. Existe como interfaz para que los tests usen una versión falsa: ningún test
 * hace peticiones a mobile.de.
 */
interface Descargador
{
    /**
     * @param string $url dirección ya validada y reconstruida con Enlace::validar()
     * @return array{ok: bool, estado: ?int, cuerpo: ?string, tipo_error: ?string}
     *   tipo_error: bloqueado (403/429, desafío anti-bot), no_existe (404/410), tiempo, red, demasiado_grande,
     *   redireccion (a otro sitio), servidor (5xx), contenido (no es HTML), sin_curl
     */
    public function obtener(string $url): array;
}
