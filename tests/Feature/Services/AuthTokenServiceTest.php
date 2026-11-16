<?php

namespace Tests\Feature\Services;

use App\Models\LspUser;
use App\Models\User;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthTokenServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string IP = '203.0.113.10';

    public function test_issues_lsp_user_token_for_valid_credentials(): void
    {
        $lspUser = LspUser::factory()->create();

        $token = $this->service()->issueLspUserToken($lspUser->email, 'password', 'device', self::IP);

        $this->assertNotEmpty($token->plainTextToken);
        $this->assertSame(1, $lspUser->tokens()->count());
    }

    public function test_rejects_wrong_password(): void
    {
        $operator = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service()->issueOperatorToken($operator->email, 'wrong-password', 'device', self::IP);
    }

    public function test_rejects_unknown_email(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->issueOperatorToken('nobody@example.com', 'password', 'device', self::IP);
    }

    public function test_locks_out_after_max_failed_attempts_even_with_correct_password(): void
    {
        $operator = User::factory()->create();
        $this->failAttempts(fn () => $this->service()->issueOperatorToken($operator->email, 'wrong', 'device', self::IP));

        try {
            $this->service()->issueOperatorToken($operator->email, 'password', 'device', self::IP);
            $this->fail('Expected lockout.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Too many login attempts', $exception->errors()['email'][0]);
        }

        $this->assertSame(0, $operator->tokens()->count());
    }

    public function test_lockout_is_scoped_per_actor_type(): void
    {
        $operator = User::factory()->create(['email' => 'shared@example.com']);
        $lspUser = LspUser::factory()->create(['email' => 'shared@example.com']);
        $this->failAttempts(fn () => $this->service()->issueOperatorToken($operator->email, 'wrong', 'device', self::IP));

        $this->service()->issueLspUserToken($lspUser->email, 'password', 'device', self::IP);

        $this->assertSame(1, $lspUser->tokens()->count());
    }

    public function test_token_expires_after_configured_minutes(): void
    {
        $this->freezeSecond();
        config(['sanctum.expiration' => 60]);
        $operator = User::factory()->create();

        $token = $this->service()->issueOperatorToken($operator->email, 'password', 'device', self::IP);

        $this->assertTrue($token->accessToken->expires_at->equalTo(now()->addMinutes(60)));
    }

    public function test_token_has_no_expiry_when_expiration_is_disabled(): void
    {
        config(['sanctum.expiration' => null]);
        $operator = User::factory()->create();

        $token = $this->service()->issueOperatorToken($operator->email, 'password', 'device', self::IP);

        $this->assertNull($token->accessToken->expires_at);
    }

    private function failAttempts(callable $attempt): void
    {
        for ($i = 0; $i < AuthTokenService::MAX_ATTEMPTS; $i++) {
            try {
                $attempt();
            } catch (ValidationException) {
            }
        }
    }

    private function service(): AuthTokenService
    {
        return $this->app->make(AuthTokenService::class);
    }
}
