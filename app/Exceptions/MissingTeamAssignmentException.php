<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class MissingTeamAssignmentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The job is not assigned to an LSP team and cannot be booked.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
