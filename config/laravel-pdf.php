<?php

/*
 * Which of spatie/laravel-pdf's drivers renders an exported table.
 *
 * dompdf is pure PHP: nothing has to be installed on the server beyond the package
 * itself, which is what a starter kit can promise will work the day it is deployed.
 * Browsershot renders the page in a real Chrome and is worth the Node and Chrome it
 * needs where an export has to look like the screen it came from — set
 * LARAVEL_PDF_DRIVER and install that driver's own package to swap.
 *
 * Only the driver is named here. Everything else the package offers — its cache, the
 * per-driver settings, the queued job — is merged in from its own config file, so
 * this file stays the one decision a project actually makes.
 */

return [
    'driver' => env('LARAVEL_PDF_DRIVER', 'dompdf'),
];
