<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CapacityRequest;
use App\Models\LspTeam;
use App\Services\AppointmentCapacityService;
use Illuminate\Http\JsonResponse;

class TeamCapacityController extends Controller
{
    public function __construct(private AppointmentCapacityService $capacity) {}

    /**
     * Daily capacity of a team on a business (Asia/Yangon) date.
     */
    public function show(CapacityRequest $request, LspTeam $lspTeam): JsonResponse
    {
        return response()->json($this->capacity->capacityFor($lspTeam, $request->validated('date'))->toArray());
    }
}
