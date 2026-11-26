<?php

use Illuminate\Support\Facades\Artisan;

// Un comando de consola, no una ruta.
Artisan::command('inspire', function () {
    $this->comment('...');
});
