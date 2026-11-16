<?php

namespace Tests\Feature\Http;

use App\Models\LspUser;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthTokenControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_lsp_user_receives_token_named_after_user_agent(): void
    {
        $lspUser = LspUser::factory()->create();

        $response = $this->withHeader('User-Agent', 'FieldApp/2.1 (Android 14)')
            ->postJson(route('auth.lsp-users.token'), ['email' => $lspUser->email, 'password' => 'password']);

        $response->assertCreated()->assertJsonStructure(['token', 'token_type', 'expires_at']);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $lspUser->getMorphClass(),
            'tokenable_id' => $lspUser->id,
            'name' => 'FieldApp/2.1 (Android 14)',
        ]);
    }

    public function test_operator_receives_token(): void
    {
        $operator = User::factory()->create();

        $response = $this->postJson(route('auth.operators.token'), ['email' => $operator->email, 'password' => 'password']);

        $response->assertCreated()->assertJsonPath('token_type', 'Bearer');
        $this->assertSame(1, $operator->tokens()->count());
    }

    public function test_response_includes_expiry_from_config(): void
    {
        $this->freezeSecond();
        config(['sanctum.expiration' => 30]);
        $operator = User::factory()->create();

        $this->postJson(route('auth.operators.token'), ['email' => $operator->email, 'password' => 'password'])
            ->assertCreated()
            ->assertJsonPath('expires_at', now()->addMinutes(30)->toIso8601String());
    }

    public function test_expired_token_is_rejected(): void
    {
        config(['sanctum.expiration' => 30]);
        $lspUser = LspUser::factory()->create();
        $token = $this->postJson(route('auth.lsp-users.token'), ['email' => $lspUser->email, 'password' => 'password'])
            ->json('token');

        $this->withToken($token)->getJson(route('auth.me'))->assertOk();

        $this->travel(31)->minutes();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson(route('auth.me'))->assertUnauthorized();
    }

    public function test_token_falls_back_to_default_name_without_user_agent(): void
    {
        $operator = User::factory()->create();

        $this->withHeader('User-Agent', '')
            ->postJson(route('auth.operators.token'), ['email' => $operator->email, 'password' => 'password'])
            ->assertCreated();

        $this->assertSame('api', $operator->tokens()->value('name'));
    }

    public function test_device_name_in_body_is_ignored(): void
    {
        $operator = User::factory()->create();

        $this->withHeader('User-Agent', 'OperatorConsole')
            ->postJson(route('auth.operators.token'), [
                'email' => $operator->email,
                'password' => 'password',
                'device_name' => 'spoofed',
            ])
            ->assertCreated();

        $this->assertSame('OperatorConsole', $operator->tokens()->value('name'));
    }

    public function test_operator_credentials_are_rejected_on_lsp_user_endpoint(): void
    {
        $operator = User::factory()->create();

        $this->postJson(route('auth.lsp-users.token'), ['email' => $operator->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => __('auth.failed')]);
    }

    public function test_email_and_password_are_required(): void
    {
        $this->postJson(route('auth.lsp-users.token'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
