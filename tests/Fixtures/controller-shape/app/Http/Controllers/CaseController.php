<?php

namespace App\Http\Controllers;

use APP\HTTP\Requests\post\INDEXREQUEST;

/**
 * PHP no distingue mayúsculas en los nombres de clase: la request es la del
 * proyecto aunque se importe así.
 */
class CaseController extends Controller
{
    public function index(INDEXREQUEST $request)
    {
        return $request->handle();
    }
}
