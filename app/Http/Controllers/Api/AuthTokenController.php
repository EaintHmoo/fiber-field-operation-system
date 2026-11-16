<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueTokenRequest;
use App\Models\LspUser;
use App\Models\User;
use App\Services\AuthTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues Sanctum personal access tokens. LSP users and operators live in separate tables,
 * so each has its own endpoint; the token's tokenable model identifies the actor type.
 */
class AuthTokenController extends Controller
{
    public function __construct(private AuthTokenService $authTokens) {}

    public function storeLspUser(IssueTokenRequest $request): JsonResponse
    {
        return $this->tokenResponse($this->authTokens->issueLspUserToken(
            $request->email(),
            $request->password(),
            $request->deviceName(),
            (string) $request->ip(),
        ));
    }

    public function storeOperator(IssueTokenRequest $request): JsonResponse
    {
        return $this->tokenResponse($this->authTokens->issueOperatorToken(
            $request->email(),
            $request->password(),
            $request->deviceName(),
            (string) $request->ip(),
        ));
    }

    /**
     * The authenticated actor, for checking which identity a token belongs to.
     */
    public function show(Request $request): JsonResponse
    {
        $actor = $request->user();

        return response()->json([
            'actor_type' => $actor instanceof LspUser ? 'lsp_user' : 'operator',
            'id' => $actor->id,
            'name' => $actor->name,
            'email' => $actor->email,
            'lsp_id' => $actor instanceof LspUser ? $actor->lsp_id : null,
            'lsp_team_id' => $actor instanceof LspUser ? $actor->lsp_team_id : null,
            'is_super_admin' => $actor instanceof User ? $actor->is_super_admin : null,
        ]);
    }

    /**
     * Revoke the token used for this request.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function tokenResponse(NewAccessToken $token): JsonResponse
    {
        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ], 201);
    }
}
