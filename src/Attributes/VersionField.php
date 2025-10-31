<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Attributes;

use Attribute;

/**
 * Class VersionField
 *
 * @author Amondar-SO
 */
#[Attribute(Attribute::TARGET_CLASS)]
readonly class VersionField
{
    /**
     * VersionField constructor.
     */
    public function __construct(public string $field)
    {
        //
    }

}
