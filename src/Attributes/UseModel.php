<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Attributes;

use Attribute;

/**
 * Class UseModel
 *
 * @author Amondar-SO
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UseModel
{
    /**
     * UseModel constructor.
     */
    public function __construct(public string $modelClass)
    {
        //
    }

}
