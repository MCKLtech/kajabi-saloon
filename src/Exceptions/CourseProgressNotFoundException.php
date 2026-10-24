<?php

namespace WooNinja\KajabiSaloon\Exceptions;

use Saloon\Exceptions\Request\ClientException;
use Saloon\Http\Response;

/**
 * Thrown on HTTP 404 from GET /v1/course_progress
 *
 * Kajabi answers 404 (with no detail saying which) when the product:
 * - isn't a course: AccessGroup (community/membership), CoachingProgram and
 *   podcast products (verified live), and cohort courses (per Kajabi's docs;
 *   use webhooks for those)
 * - doesn't exist on this account
 *
 * All of these are permanent for that product id: stop polling it rather than
 * retrying. Product::$product_type_name tells the cases apart up front - only
 * "Course" products have progress.
 *
 * Extends ClientException so existing `catch (RequestException)` blocks still apply.
 */
class CourseProgressNotFoundException extends ClientException
{
    public function __construct(Response $response, public readonly int $productId)
    {
        parent::__construct(
            $response,
            "Kajabi has no course progress for product {$productId}: it is not a course (or a cohort course), or not on this account (HTTP 404)."
        );
    }
}
