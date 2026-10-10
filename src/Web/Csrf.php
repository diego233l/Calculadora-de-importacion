<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/**
 * Protección CSRF sin sesiones ni cuentas.
 *
 * El navegador recibe una cookie aleatoria (HttpOnly, SameSite=Lax) y cada formulario lleva un campo oculto con
 * HMAC(cookie, clave del servidor). Un sitio ajeno no puede leer la cookie ni calcular el HMAC, y tampoco le sirve
 * plantar su propia cookie: no sabría firmarla con la clave del servidor.
 */
final class Csrf
{
    public const COOKIE = 'ci_csrf';
    public const CAMPO = 'csrf';

    /** Devuelve el valor del campo oculto y, si el navegador aún no tiene cookie, la añade a la respuesta. */
    public static function emitir(Peticion $peticion, Respuesta $respuesta, string $secreto): string
    {
        $cookie = $peticion->cookies[self::COOKIE] ?? '';
        if (!is_string($cookie) || !preg_match('/^[a-f0-9]{32}$/', $cookie)) {
            $cookie = bin2hex(random_bytes(16));
            $respuesta->conCookie(self::COOKIE, $cookie, [
                'path' => '/',
                'httponly' => true,
                'secure' => $peticion->https,
                'samesite' => 'Lax',
            ]);
        }
        return hash_hmac('sha256', $cookie, $secreto);
    }

    public static function validar(Peticion $peticion, string $secreto): bool
    {
        $cookie = $peticion->cookies[self::COOKIE] ?? '';
        $campo = $peticion->post[self::CAMPO] ?? '';
        if (!is_string($cookie) || !is_string($campo) || !preg_match('/^[a-f0-9]{32}$/', $cookie) || $campo === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $cookie, $secreto), $campo);
    }
}
