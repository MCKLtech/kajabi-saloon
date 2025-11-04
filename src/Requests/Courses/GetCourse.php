<?php

namespace WooNinja\KajabiSaloon\Requests\Courses;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;

class GetCourse extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private int $courseId
    ) {}

    public function resolveEndpoint(): string
    {
        return "/courses/{$this->courseId}";
    }

    public function createDtoFromResponse($response): Course
    {
        $data = $response->json('data');
        
        return Course::fromKajabiCourse($data);
    }
}
