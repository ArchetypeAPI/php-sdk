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
        Archetype::init(['app_id' => config('archetype.app_id'), 'secret_key' => config('archetype.secret_key')], $mute = true);
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('auth.archetype', AuthenticateArchetype::class);
    }
}