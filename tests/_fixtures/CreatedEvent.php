<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class CreatedEvent
 *
 * @author Amondar-SO
 */
readonly class CreatedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * CreatingEvent constructor.
     */
    public function __construct(public User $model, public array $data)
    {
        //
    }

}
