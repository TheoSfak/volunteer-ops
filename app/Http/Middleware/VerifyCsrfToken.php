<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * URIs που εξαιρούνται από CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/*',
    ];
}
