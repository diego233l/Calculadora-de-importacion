<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/**
 * Plantillas PHP de src/Vistas/. Toda salida pasa por e() (htmlspecialchars): una plantilla nunca imprime un
 * dato sin escapar. Los números y los euros se formatean a la española.
 */
final class Vista
{
    public static function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function num(float $valor, int $decimales = 0): string
    {
        return number_format($valor, $decimales, ',', '.');
    }

    /** "1.234,56 €" con espacio duro, para que el símbolo no quede solo en otra línea. */
    public static function eur(?float $valor): string
    {
        return $valor === null ? '—' : number_format($valor, 2, ',', '.') . "\u{00a0}€";
    }

    public static function pct(float $valor): string
    {
        $t = rtrim(rtrim(number_format($valor, 2, ',', '.'), '0'), ',');
        return $t . "\u{00a0}%";
    }

    /** Fecha "AAAA-MM-DD" como "10/10/2026". */
    public static function fecha(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] : $iso;
    }

    /** Versión de un recurso estático (su fecha de modificación) para que el navegador lo recargue al cambiar. */
    public static function version(string $rutaPublica): string
    {
        $f = Config::raiz() . '/public' . $rutaPublica;
        return is_file($f) ? (string) filemtime($f) : '0';
    }

    /** Una vista suelta, sin el marco de la página. */
    public static function renderizar(string $nombre, array $d = []): string
    {
        if (!preg_match('/^[a-z0-9_\/-]+$/', $nombre)) {
            throw new \InvalidArgumentException('Nombre de vista no válido');
        }
        $archivo = Config::raiz() . '/src/Vistas/' . $nombre . '.php';
        // Las claves del array pasan a ser variables de la plantilla ($csrf, $valores…)
        $render = static function (string $__archivo, array $__datos): string {
            extract($__datos, EXTR_SKIP);
            ob_start();
            try {
                include $__archivo;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };
        return $render($archivo, $d);
    }

    /**
     * Una página completa: la vista dentro del marco común.
     * @param array{titulo: string, descripcion?: string, ruta?: string, robots?: string, clase?: string, activa?: string} $meta
     */
    public static function pagina(string $vista, array $d, array $meta): string
    {
        $contenido = self::renderizar($vista, $d);
        return self::renderizar('marco', ['contenido' => $contenido, 'meta' => $meta]);
    }
}
