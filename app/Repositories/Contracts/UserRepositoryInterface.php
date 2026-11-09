<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface UserRepositoryInterface extends RepositoryInterface
{
    public function findByEmail(string $email): ?User;

    /** Sign-in: the text typed is either an email or (student assistants) a username. */
    public function findByLogin(string $login): ?User;

    public function findByUuid(string $uuid): ?User;
}
