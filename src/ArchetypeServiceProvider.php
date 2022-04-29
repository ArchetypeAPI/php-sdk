<?php 

namespace Archetype;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Archetype\Http\Middleware\AuthenticateArchetype;
use Illuminate\Routing\Router;

class ArchetypeServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot(Kernel $kernel)
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
              __DIR__.'/../config/archetype.php' => config_path('archetype.php'),
            ], 'config');
        }
    }
}