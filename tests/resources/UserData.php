<?php

declare(strict_types = 1);

namespace Tests\resources;

use Illuminate\Support\Optional;
use Spatie\LaravelData\Data;

/**
 * Class UserData
 *
 * @author Amondar-SO
 */
class UserData extends Data
{
    public string $name;

    public string $email;

    public string|Optional|null $password;

    public bool $is_active;

    public bool $is_admin;
}
