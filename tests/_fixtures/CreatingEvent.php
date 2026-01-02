<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class CreatingEvent
 *
 * @author Amondar-SO
 */
readonly class CreatingEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * CreatingEvent constructor.
     */
    public function __construct(public string $email)
    {
        //
    }

}
