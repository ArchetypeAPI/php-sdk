<?php 

return [
    
    /**
     * The app_id of your Archetype application
     * 
     */
    'app_id' => env('ARCHETYPE_APP_ID'),
    /**
     * The secret_key of your Archetype application
     * 
     */
    'secret_key' => env('ARCHETYPE_SECRET_KEY'),

    // Use archetyp authentication middleware
    'authorizing_via_archetype' => env('ARCHETYPE_AUTHORIZING_VIA_ARCHETYPE', true),
];