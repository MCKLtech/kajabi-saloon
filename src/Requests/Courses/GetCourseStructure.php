<?php

namespace WooNinja\KajabiSaloon\Requests\Courses;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\CourseStructure;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;

/**
 * GET /v1/courses/{id}?include=modules,lessons
 *
 * The course id is the product id. Products that aren't courses (communities,
 * coaching) answer 404; an id not on the account answers 403.
 */
class GetCourseStructure extends Request
{
    use JsonApiQuery;

    protected Method $method = Method::GET;

    public function __construct(
        private int $courseId
    ) {}

    public function resolveEndpoint(): string
    {
        return "/courses/{$this->courseId}";
    }

    protected function defaultQuery(): array
    {
        return ['include' => 'modules,lessons'];
    }

    public function createDtoFromResponse($response): CourseStructure
    {
        return CourseStructure::fromKajabiResponse($response->json());
    }
}
