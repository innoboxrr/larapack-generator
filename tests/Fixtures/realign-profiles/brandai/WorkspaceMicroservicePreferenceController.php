<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La forma de un controlador de brandai (recortado de
 * WorkspaceMicroservicePreferenceController): sin contrato, recibe Request,
 * valida y consulta en el controlador. verify lo mira igual aunque el
 * proyecto no use LaraPack.
 */
class WorkspaceMicroservicePreferenceController extends Controller
{
    public function update(Request $request, Workspace $workspace, int $microserviceId): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        DB::table('workspace_microservice_preferences')->updateOrInsert(
            ['workspace_id' => $workspace->id, 'microservice_id' => $microserviceId],
            ['is_enabled' => (bool) $validated['is_enabled']],
        );

        return response()->json(['data' => ['microservice_id' => $microserviceId, 'is_enabled' => (bool) $validated['is_enabled']]]);
    }
}
