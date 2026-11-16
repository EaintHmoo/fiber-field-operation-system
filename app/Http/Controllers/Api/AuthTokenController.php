<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueTokenRequest;
use App\Models\LspUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Issues Sanctum personal access tokens. LSP users and operators live in separate tables,
 * so each has its own endpoint; the token's tokenable model identifies the actor type.
 */
class AuthTokenController extends Controller
{
    public function storeLspUser(IssueTokenRequest $request): JsonResponse
    {
        return $this->issue($request->authenticate(LspUser::class), $request->deviceName());
    }

    public function storeOperator(IssueTokenRequest $request): JsonResponse
    {
        return $this->issue($request->authenticate(User::class), $request->deviceName());
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

    private function issue(User|LspUser $actor, string $deviceName): JsonResponse
    {
        return response()->json([
            'token' => $actor->createToken($deviceName)->plainTextToken,
            'token_type' => 'Bearer',
        ], 201);
    }
}
