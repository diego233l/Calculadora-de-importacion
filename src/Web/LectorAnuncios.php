<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

use CalculadoraImportacion\Anuncio\Analizador;
use CalculadoraImportacion\Anuncio\Descargador;

/**
 * Lee un anuncio desde su enlace o desde el texto que pega el usuario, y devuelve los datos con su confianza.
 *
 * Desde el enlace: caché de 24 h (solo los datos leídos, nunca el HTML) → límites (10 por minuto y por IP, 1 por segundo
 * en total) → UNA descarga → análisis. Si mobile.de bloquea la lectura, no se insiste: se explica y se ofrece pegar el texto.
 * Todo fallo se registra por tipo (sin IP ni enlaces) para saber qué falla sin guardar datos de nadie.
 */
final class LectorAnuncios
{
    public const LIMITE_POR_IP = 10;
    public const LIMITE_GLOBAL_POR_SEGUNDO = 1;

    public function __construct(
        private readonly Almacen $almacen,
        private readonly string $dirVar,
        private readonly Analizador $analizador,
        private readonly Descargador $descargador,
        /** @var (\Closure(): int)|null devuelve la hora actual (los tests la fijan para probar los límites) */
        private readonly ?\Closure $reloj = null,
    ) {
    }

    /**
     * @param array{id: string, canonica: string} $enlace resultado válido de Enlace::validar()
     * @return array{estado: string, mensaje: ?string, lectura: ?array, desde_cache: bool}
     *   estado: ok | bloqueado | no_existe | limite | error | ilegible
     */
    public function leerEnlace(string $ip, array $enlace, string $hoy): array
    {
        $ahora = $this->reloj !== null ? ($this->reloj)() : null;
        $cache = $this->almacen->leerAnuncio($enlace['id'], 86400, $ahora);
        if ($cache !== null) {
            return ['estado' => 'ok', 'mensaje' => null, 'lectura' => $cache, 'desde_cache' => true];
        }
        if (!$this->almacen->permitir('lectura:' . $ip, self::LIMITE_POR_IP, 60, $ahora)) {
            Registro::escribir($this->dirVar, 'lectura_limite_ip');
            return $this->sin('limite', 'Has pedido leer muchos anuncios seguidos. Espera un minuto o pega el texto del anuncio, que no tiene límite.');
        }
        if (!$this->almacen->permitir('lectura:global', self::LIMITE_GLOBAL_POR_SEGUNDO, 1, $ahora)) {
            Registro::escribir($this->dirVar, 'lectura_limite_global');
            return $this->sin('limite', 'Estamos leyendo otro anuncio en este momento. Vuelve a intentarlo en unos segundos o pega el texto del anuncio.');
        }
        $r = $this->descargador->obtener($enlace['canonica']);
        if (!$r['ok'] || $r['cuerpo'] === null) {
            $tipo = (string) ($r['tipo_error'] ?? 'red');
            Registro::escribir($this->dirVar, 'descarga_' . $tipo, 'estado=' . ($r['estado'] ?? '-'));
            return $this->sin($tipo === 'no_existe' ? 'no_existe' : ($tipo === 'bloqueado' ? 'bloqueado' : 'error'), self::mensajeDeError($tipo));
        }
        $lectura = $this->analizador->analizarHtml($r['cuerpo'], $hoy);
        if ($lectura['campos'] === []) {
            Registro::escribir($this->dirVar, 'anuncio_ilegible', 'estado=' . ($r['estado'] ?? '-') . ' bytes=' . strlen($r['cuerpo']));
            return $this->sin('ilegible', 'Hemos descargado la página, pero no hemos reconocido en ella los datos de un coche (puede que mobile.de haya cambiado su diseño o que no sea un anuncio). Pega el texto del anuncio y lo leemos desde ahí.');
        }
        $this->registrarDesconocidos($lectura);
        $this->almacen->guardarAnuncio($enlace['id'], $lectura, $ahora);
        return ['estado' => 'ok', 'mensaje' => null, 'lectura' => $lectura, 'desde_cache' => false];
    }

    /** @return array{estado: string, mensaje: ?string, lectura: ?array, desde_cache: bool} */
    public function leerTexto(string $texto, string $hoy): array
    {
        $lectura = $this->analizador->analizarTexto($texto, $hoy);
        if ($lectura['campos'] === []) {
            Registro::escribir($this->dirVar, 'texto_ilegible', 'caracteres=' . mb_strlen($texto));
            return $this->sin('ilegible', 'No hemos reconocido datos de un coche en ese texto. Copia toda la página del anuncio (Ctrl+A y Ctrl+C) y pégala entera; si sigue sin funcionar, rellena los datos a mano.');
        }
        $this->registrarDesconocidos($lectura);
        return ['estado' => 'ok', 'mensaje' => null, 'lectura' => $lectura, 'desde_cache' => false];
    }

    private static function mensajeDeError(string $tipo): string
    {
        $pegar = ' Abre el anuncio en tu navegador, copia todo el texto de la página (Ctrl+A y Ctrl+C) y pégalo en el cuadro de abajo.';
        return match ($tipo) {
            'bloqueado' => 'mobile.de no deja que nuestro servidor lea el anuncio: bloquea las lecturas automáticas, y no intentamos saltarnos ese bloqueo.' . $pegar,
            'no_existe' => 'mobile.de dice que ese anuncio no existe (puede haberse vendido o borrado). Comprueba el enlace.',
            'tiempo' => 'mobile.de ha tardado demasiado en responder.' . $pegar,
            'demasiado_grande', 'contenido' => 'La respuesta de mobile.de no tiene el formato esperado.' . $pegar,
            default => 'Ahora mismo no hemos podido leer el anuncio en mobile.de.' . $pegar,
        };
    }

    /** @return array{estado: string, mensaje: ?string, lectura: ?array, desde_cache: bool} */
    private function sin(string $estado, string $mensaje): array
    {
        return ['estado' => $estado, 'mensaje' => $mensaje, 'lectura' => null, 'desde_cache' => false];
    }

    /** Apunta los términos que el diccionario no conoce (un combustible nuevo, una marca) para ampliarlo; sin datos personales. */
    private function registrarDesconocidos(array $lectura): void
    {
        if ($lectura['desconocidos'] !== []) {
            Registro::escribir($this->dirVar, 'termino_desconocido', implode('; ', array_slice($lectura['desconocidos'], 0, 5)));
        }
    }
}
