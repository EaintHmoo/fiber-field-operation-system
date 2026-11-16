<?php

namespace App\Http\Requests;

use App\Models\LspUser;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueTokenRequest extends FormRequest
{
    private const int MAX_ATTEMPTS = 5;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * Resolve the account of the given model class from the credentials, rate limited per email + IP.
     *
     * @template TUser of User|LspUser
     *
     * @param  class-string<TUser>  $userModel
     * @return TUser
     *
     * @throws ValidationException
     */
    public function authenticate(string $userModel): User|LspUser
    {
        $throttleKey = Str::transliterate(Str::lower($this->string('email')).'|'.$userModel.'|'.$this->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            event(new Lockout($this));

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                    'minutes' => ceil(RateLimiter::availableIn($throttleKey) / 60),
                ]),
            ]);
        }

        $user = $userModel::query()->where('email', $this->string('email')->toString())->first();

        if ($user === null || ! Hash::check($this->string('password')->toString(), $user->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);

        return $user;
    }

    public function deviceName(): string
    {
        return $this->string('device_name', 'api')->toString();
    }
}
