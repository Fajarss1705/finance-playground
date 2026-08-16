<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo reset
    |--------------------------------------------------------------------------
    |
    | When enabled, the scheduler wipes and re-seeds the database every fifteen
    | minutes so a public demo cannot be left in a broken state by whoever came
    | before. It is off by default: nothing but the deployed playground should
    | ever be running a scheduled `migrate:fresh`.
    |
    */

    'reset' => (bool) env('DEMO_RESET', false),

    /*
    |--------------------------------------------------------------------------
    | Demo credentials
    |--------------------------------------------------------------------------
    |
    | When set, the login form arrives pre-filled with this account so a visitor
    | can get in without copying anything. 🔴 These reach the browser — only
    | ever point them at the throwaway seeded account on the public demo, never
    | at a real one. Null disables the whole feature.
    |
    */

    'credentials' => env('DEMO_MODE', false) ? [
        'email' => env('DEMO_EMAIL', 'test@demo.test'),
        'password' => env('DEMO_PASSWORD', 'password123!'),
    ] : null,

];
