<?php

namespace App\Repositories\Eloquent;

use App\Models\LspUser;
use App\Models\User;
use App\Repositories\Contracts\AuthAccountRepositoryInterface;

class EloquentAuthAccountRepository implements AuthAccountRepositoryInterface
{
    public function findLspUserByEmail(string $email): ?LspUser
    {
        return LspUser::query()->where('email', $email)->first();
    }

    public function findOperatorByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }
}
