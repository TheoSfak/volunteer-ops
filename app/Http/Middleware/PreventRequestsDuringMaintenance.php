<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * URIs που επιτρέπονται κατά τη συντήρηση.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];
}
