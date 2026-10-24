<?php

namespace WooNinja\KajabiSaloon\Exceptions;

use Saloon\Exceptions\Request\ClientException;
use Saloon\Http\Response;

/**
 * Thrown on HTTP 403 from GET /v1/course_progress: the API key can't read
 * course progress. Fixing it means changing the key's access, so retrying
 * won't help.
 *
 * Extends ClientException so existing `catch (RequestException)` blocks still apply.
 */
class CourseProgressForbiddenException extends ClientException
{
    public function __construct(Response $response, public readonly int $productId)
    {
        parent::__construct(
            $response,
            "Kajabi refused course progress for product {$productId}: the API key lacks access (HTTP 403)."
        );
    }
}
