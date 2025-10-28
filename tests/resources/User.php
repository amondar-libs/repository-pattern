<?php

declare( strict_types = 1 );

namespace Tests\resources;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Class User
 *
 * @author Amondar-SO
 */
class User extends Model
{
    use HasUuids;

    protected static $unguarded = true;

    protected function casts() : array
    {
        return [
            'password'  => 'hashed',
            'is_active' => 'boolean',
            'is_admin'  => 'boolean',
        ];
    }

    public function addresses() : User|\Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

}
