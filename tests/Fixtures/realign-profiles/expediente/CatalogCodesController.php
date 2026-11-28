<?php

namespace Acme\Blog\Http\Controllers\Clinic;

use Acme\Blog\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La forma de un controlador escrito a mano en expediente (recortado de
 * CatalogCodesController): valida en el controlador, recibe Request y arma
 * la respuesta él mismo. Es lo que el paso guiado `controller-shape` lleva a
 * su FormRequest.
 */
class CatalogCodesController extends Controller
{
    private const LIMIT = 60;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        abort_if($data['source'] === 'dgis.formacion', 422, 'La formación se elige en la ficha.');

        $text = trim((string) ($data['q'] ?? ''));

        if ($text === '') {
            return response()->json(['options' => []]);
        }

        $options = DB::table('catalog_entries')
            ->where('label', 'like', '%'.addcslashes($text, '%_\\').'%')
            ->limit(self::LIMIT)
            ->get(['code', 'label']);

        return response()->json(['options' => $options]);
    }
}
