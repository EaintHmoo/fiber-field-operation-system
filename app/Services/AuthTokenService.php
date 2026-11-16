<?php

namespace App\Services;

use App\Models\LspUser;
use App\Models\User;
use App\Repositories\Contracts\AuthAccountRepositoryInterface;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Verifies credentials and issues Sanctum personal access tokens for LSP users and operators.
 *
 * Failed attempts are rate limited per actor type, email and IP. Tokens expire after
 * sanctum.expiration minutes (SANCTUM_TOKEN_EXPIRATION).
 */
class AuthTokenService
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(private AuthAccountRepositoryInterface $accounts) {}

    /**
     * @throws ValidationException when the credentials are wrong or the caller is locked out
     */
    public function issueLspUserToken(string $email, string $password, string $deviceName, string $ip): NewAccessToken
    {
        $lspUser = $this->authenticate(
            'lsp_user',
            $email,
            $password,
            $ip,
            fn (string $email): ?LspUser => $this->accounts->findLspUserByEmail($email),
        );

        return $lspUser->createToken($deviceName, expiresAt: $this->expiresAt());
    }

    /**
     * @throws ValidationException when the credentials are wrong or the caller is locked out
     */
    public function issueOperatorToken(string $email, string $password, string $deviceName, string $ip): NewAccessToken
    {
        $operator = $this->authenticate(
            'operator',
            $email,
            $password,
            $ip,
            fn (string $email): ?User => $this->accounts->findOperatorByEmail($email),
        );

        return $operator->createToken($deviceName, expiresAt: $this->expiresAt());
    }

    /**
     * Stored on the token so clients can see it; Sanctum also enforces sanctum.expiration itself.
     */
    private function expiresAt(): ?CarbonImmutable
    {
        $minutes = config('sanctum.expiration');

        return $minutes === null ? null : CarbonImmutable::now()->addMinutes((int) $minutes);
    }

    /**
     * @template TActor of User|LspUser
     *
     * @param  Closure(string): (TActor|null)  $findAccount
     * @return TActor
     *
     * @throws ValidationException
     */
    private function authenticate(string $actorType, string $email, string $password, string $ip, Closure $findAccount): User|LspUser
    {
        $throttleKey = Str::transliterate(Str::lower($email).'|'.$actorType.'|'.$ip);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        $actor = $findAccount($email);

        if ($actor === null || ! Hash::check($password, $actor->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);

        return $actor;
    }
}
