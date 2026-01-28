<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * Cookies που δεν κρυπτογραφούνται.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];
}
