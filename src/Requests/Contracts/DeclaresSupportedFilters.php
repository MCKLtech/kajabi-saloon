<?php

namespace WooNinja\KajabiSaloon\Requests\Contracts;

/**
 * A list request that knows which filter[...] names Kajabi honours for it.
 * KajabiConnector::setStrictFilters() uses this to refuse filters Kajabi
 * would silently ignore.
 */
interface DeclaresSupportedFilters
{
    /**
     * @return string[] Filter names without the filter[...] wrapper, e.g. "site_id"
     */
    public static function supportedFilters(): array;
}
