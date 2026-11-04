<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * La sesión del navegador viaja en una cookie que el JavaScript de la página no puede leer
 * (HttpOnly): si un script ajeno llegara a ejecutarse en la página, no podría llevarse el token.
 * La pone la API al iniciar sesión y la borra al cerrarla o cuando la sesión ya no vale.
 * El nombre es el de antes (auth_token), así las sesiones abiertas siguen funcionando.
 */
class SessionCookie
{
    public const NAME = 'auth_token';

    /** "Recordarme": dura lo que la sesión; sin marcar, es de sesión (se borra al cerrar el navegador). */
    public static function make(string $token, bool $remember, int $minutes): Cookie
    {
        return Cookie::create(self::NAME, $token, $remember ? now()->addMinutes($minutes) : 0, '/', null, request()->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    public static function forget(): Cookie
    {
        return Cookie::create(self::NAME, '', 1, '/', null, request()->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }
}
