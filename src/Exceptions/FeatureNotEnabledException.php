<?php

namespace WooNinja\KajabiSaloon\Exceptions;

use Saloon\Exceptions\Request\ClientException;
use Saloon\Http\Response;

/**
 * Thrown on HTTP 423 (e.g. from GET /v1/course_progress): the feature isn't
 * available for the account the API key belongs to.
 *
 * Extends ClientException so existing `catch (RequestException)` blocks still apply.
 */
class FeatureNotEnabledException extends ClientException
{
    public function __construct(Response $response, string $feature)
    {
        parent::__construct(
            $response,
            "Kajabi {$feature} is not available for this account (HTTP 423)."
        );
    }
}
