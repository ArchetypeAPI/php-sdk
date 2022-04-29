<?php

namespace Archetype\Http\Middleware;

use Closure;
use Archetype\Archetype;
use Archetype\Exceptions\ArchetypeException;

class AuthenticateArchetype
{
    public function handle($request, Closure $next)
    {
        if (! config('archetype.app_id') || ! config('archetype.secret_key'))
            throw new ArchetypeException('Archetype app_id and secret_key are not specified in config/archetype.php');

        $response = Archetype::authenticate($request);
        
        return $next($request);
    }
}