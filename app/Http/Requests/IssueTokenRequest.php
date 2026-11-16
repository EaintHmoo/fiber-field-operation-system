<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class IssueTokenRequest extends FormRequest
{
    private const string DEFAULT_DEVICE_NAME = 'api';

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }

    /**
     * Token name derived from the client's User-Agent; clients do not send it.
     */
    public function deviceName(): string
    {
        $userAgent = Str::limit(trim((string) $this->userAgent()), 255, '');

        return $userAgent !== '' ? $userAgent : self::DEFAULT_DEVICE_NAME;
    }
}
