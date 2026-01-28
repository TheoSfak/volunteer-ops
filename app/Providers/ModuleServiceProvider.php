<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Λίστα με τα διαθέσιμα modules
     */
    protected array $modules = [
        'Auth',
        'Directory',
        'Volunteers',
        'Missions',
        'Shifts',
        'Participation',
        'Documents',
        'Notifications',
        'Audit',
        'Reports',
    ];

    /**
     * Καταχώρηση υπηρεσιών.
     */
    public function register(): void
    {
        foreach ($this->modules as $module) {
            $this->registerModuleServices($module);
        }
    }

    /**
     * Εκκίνηση υπηρεσιών.
     */
    public function boot(): void
    {
        foreach ($this->modules as $module) {
            $this->bootModuleRoutes($module);
        }
    }

    /**
     * Καταχώρηση υπηρεσιών για κάθε module.
     */
    protected function registerModuleServices(string $module): void
    {
        $serviceProviderClass = "App\\Modules\\{$module}\\Providers\\{$module}ServiceProvider";
        
        if (class_exists($serviceProviderClass)) {
            $this->app->register($serviceProviderClass);
        }
    }

    /**
     * Φόρτωση routes για κάθε module.
     */
    protected function bootModuleRoutes(string $module): void
    {
        $routesPath = app_path("Modules/{$module}/routes.php");
        
        if (file_exists($routesPath)) {
            Route::prefix('api')
                ->middleware('api')
                ->group($routesPath);
        }
    }
}
