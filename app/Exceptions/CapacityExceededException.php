<?php

namespace App\Exceptions;

use App\Services\AppointmentCapacityService;
use App\Services\CapacitySnapshot;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CapacityExceededException extends RuntimeException
{
    /**
     * @param  bool  $canOverride  Whether the actor holds the override permission and could retry with an override_reason.
     */
    public function __construct(
        public readonly CapacitySnapshot $capacity,
        public readonly bool $canOverride = false,
    ) {
        parent::__construct($canOverride
            ? 'The team has no remaining appointment slots on this date. Provide an override_reason to book over capacity.'
            : 'The team has no remaining appointment slots on this date.');
    }

    public function render(): JsonResponse
    {
        $body = [
            'message' => $this->getMessage(),
            ...$this->capacity->toArray(),
        ];

        if ($this->canOverride) {
            $body['errors'] = [
                'override_reason' => [
                    'The override reason is required to book over capacity (at least '.AppointmentCapacityService::MIN_OVERRIDE_REASON_LENGTH.' characters).',
                ],
            ];
        }

        return response()->json($body, 422);
    }
}
