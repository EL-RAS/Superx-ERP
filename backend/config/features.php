<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | V1 soft-disable switch for optional platform modules. Every flag here
    | is a lazy environment toggle — controllers, models, tables and relations
    | are preserved (V2-ready); only routes, navigation and client UI honour
    | the flag at runtime.
    |
    */

    'crm_enabled' => (bool) env('FEATURES_CRM_ENABLED', false),

];