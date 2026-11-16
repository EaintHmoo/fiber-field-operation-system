<?php

namespace App\Repositories\Contracts;

use App\Models\LspUser;
use App\Models\User;

/**
 * Looks up login accounts. LSP users and operators live in separate tables.
 */
interface AuthAccountRepositoryInterface
{
    public function findLspUserByEmail(string $email): ?LspUser;

    public function findOperatorByEmail(string $email): ?User;
}
