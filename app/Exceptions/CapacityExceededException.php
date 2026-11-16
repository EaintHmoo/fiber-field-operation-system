<?php

namespace App\Exceptions;

use App\Services\CapacitySnapshot;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CapacityExceededException extends RuntimeException
{
    public function __construct(public readonly CapacitySnapshot $capacity)
    {
        parent::__construct('The team has no remaining appointment slots on this date.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            ...$this->capacity->toArray(),
        ], 422);
    }
}
