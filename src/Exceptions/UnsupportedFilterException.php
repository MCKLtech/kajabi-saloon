<?php

namespace WooNinja\KajabiSaloon\Exceptions;

/**
 * Thrown before sending, in strict filter mode, when a request carries a
 * filter or parameter Kajabi doesn't support. Kajabi would ignore it and
 * return the unfiltered list (verified live), so the result would look
 * correct while matching everything.
 */
class UnsupportedFilterException extends \InvalidArgumentException
{
    /**
     * @param string[] $parameters
     */
    public function __construct(public readonly string $request, public readonly array $parameters)
    {
        parent::__construct(sprintf(
            '%s: Kajabi ignores %s, so the request would return unfiltered results. '
            . 'See %s::supportedFilters().',
            $request,
            implode(', ', $parameters),
            $request
        ));
    }
}
