<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Xania Shield API key
    |--------------------------------------------------------------------------
    | Your site API key from app.xaniashield.com. Travels in a request header,
    | so it must only ever be sent over HTTPS.
    */
    'api_key' => env('SHIELD_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | API base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('SHIELD_BASE_URL', 'https://xaniashield.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Request timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('SHIELD_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Fail-open
    |--------------------------------------------------------------------------
    | When the API is unreachable, times out, or errors: true => allow the
    | submission (never break your forms), false => block on uncertainty.
    */
    'fail_open' => (bool) env('SHIELD_FAIL_OPEN', true),

    /*
    |--------------------------------------------------------------------------
    | Timing secret
    |--------------------------------------------------------------------------
    | A long, random, server-side-only string used to HMAC-sign the time-to-fill
    | token. Required only if you use the timing field. Never expose to the browser.
    */
    'timing_secret' => env('SHIELD_TIMING_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Verdict thresholds (client-side interpretation)
    |--------------------------------------------------------------------------
    | The API returns its own action, but you can also act on the raw score.
    | These are used only by helper methods that read the numeric score.
    */
    'challenge_threshold' => (int) env('SHIELD_CHALLENGE_THRESHOLD', 40),
    'block_threshold'     => (int) env('SHIELD_BLOCK_THRESHOLD', 70),

    /*
    |--------------------------------------------------------------------------
    | Hidden field names
    |--------------------------------------------------------------------------
    | honeypot: the field bots fill and humans never see.
    | timing:   the signed render-timestamp field.
    */
    'honeypot_field' => env('SHIELD_HONEYPOT_FIELD', 'website_url'),
    'timing_field'   => env('SHIELD_TIMING_FIELD', 'xsh_tf'),

];
