<?php

namespace Tests\resources;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Class UserAddress
 *
 * @author Amondar-SO
 */
class UserAddress extends Model
{

    use HasUuids;

    protected static $unguarded = true;
}