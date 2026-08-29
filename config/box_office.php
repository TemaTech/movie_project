<?php

return [

    /*
    |--------------------------------------------------------------------------
    | History Storage Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "file" (default). A future "database" driver can replace the
    | file-backed stores without changing fetch/export commands.
    |
    */

    'driver' => env('BOX_OFFICE_HISTORY_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | File History Path
    |--------------------------------------------------------------------------
    |
    | CI and production builds use the default "data/history" directory, which
    | GitHub Actions commits after each fetch. For local development, set
    | BOX_OFFICE_HISTORY_PATH to a gitignored directory so fetch runs do not
    | dirty the working tree.
    |
    */

    'history_path' => env('BOX_OFFICE_HISTORY_PATH'),

    /*
    |--------------------------------------------------------------------------
    | USD to JPY (approximate)
    |--------------------------------------------------------------------------
    |
    | Worldwide totals are stored in USD. The site shows a yen estimate at this
    | fixed rate so ranking pages stay comparable without a live FX feed.
    |
    */

    'usd_jpy' => (float) env('BOX_OFFICE_USD_JPY', 150),

];
