<?php

namespace Acme\Billing\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Un paquete local: se revisa igual que la aplicación.
 */
class InvoiceController
{
    public function index(Request $request)
    {
        $request->validate(['page' => ['integer']]);

        return [];
    }
}
