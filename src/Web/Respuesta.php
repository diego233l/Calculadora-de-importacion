<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/** Respuesta HTTP: se construye sin tocar nada global y solo se emite al final, en enviar(). */
final class Respuesta
{
    /** @var array<string, string> */
    public array $cabeceras = [];
    /** @var array<int, array{nombre: string, valor: string, opciones: array}> */
    public array $cookies = [];

    public function __construct(public int $estado = 200, public string $cuerpo = '', array $cabeceras = [])
    {
        foreach ($cabeceras as $nombre => $valor) {
            $this->cabeceras[$nombre] = $valor;
        }
    }

    public static function html(string $cuerpo, int $estado = 200): self
    {
        return new self($estado, $cuerpo, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(array $datos, int $estado = 200): self
    {
        return new self($estado, (string) json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function texto(string $cuerpo, int $estado = 200): self
    {
        return new self($estado, $cuerpo, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** Redirección interna: solo se aceptan rutas que empiecen por una sola barra. */
    public static function redirigir(string $ruta, int $estado = 303): self
    {
        if (!preg_match('#^/(?!/)#', $ruta)) {
            $ruta = '/';
        }
        return new self($estado, '', ['Location' => $ruta]);
    }

    public function conCabecera(string $nombre, string $valor): self
    {
        $this->cabeceras[$nombre] = $valor;
        return $this;
    }

    public function conCookie(string $nombre, string $valor, array $opciones): self
    {
        $this->cookies[] = ['nombre' => $nombre, 'valor' => $valor, 'opciones' => $opciones];
        return $this;
    }

    public function enviar(bool $conCuerpo = true): void
    {
        http_response_code($this->estado);
        foreach ($this->cabeceras as $nombre => $valor) {
            header($nombre . ': ' . $valor);
        }
        foreach ($this->cookies as $c) {
            setcookie($c['nombre'], $c['valor'], $c['opciones']);
        }
        if ($conCuerpo) {
            echo $this->cuerpo;
        }
    }
}
