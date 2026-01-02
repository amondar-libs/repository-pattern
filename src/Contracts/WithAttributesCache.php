<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Contracts;

use Spatie\StructureDiscoverer\Cache\DiscoverCacheDriver;

/**
 * Interface WithAttributesCache
 *
 * @author Amondar-SO
 */
interface WithAttributesCache
{
    /**
     * Retrieves the attribute cache store using by the current class.
     */
    public static function getAttributesCache(): DiscoverCacheDriver;
}
