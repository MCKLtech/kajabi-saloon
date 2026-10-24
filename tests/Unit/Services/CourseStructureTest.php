<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\CourseStructure;
use WooNinja\KajabiSaloon\DataTransferObjects\Lessons\Lesson;
use WooNinja\KajabiSaloon\DataTransferObjects\Modules\Module;
use WooNinja\KajabiSaloon\Requests\Courses\GetCourseStructure;
use WooNinja\KajabiSaloon\Services\KajabiService;

/**
 * GET /v1/courses/{id}?include=modules,lessons
 *
 * The fixture mirrors the live response: integer positions starting at 0,
 * lessons linked to their module, modules listing their lessons, and no
 * product relationship on the course. Included resources arrive out of order.
 */
class CourseStructureTest extends TestCase
{
    private static function lesson(int $id, string $title, int $position, int $moduleId, string $publishing = 'published'): array
    {
        return [
            'id' => (string) $id,
            'type' => 'lessons',
            'attributes' => ['title' => $title, 'position' => $position, 'status' => 'ready', 'publishing_option' => $publishing],
            'relationships' => [
                'module' => ['data' => ['id' => (string) $moduleId, 'type' => 'modules']],
                'media' => ['data' => ['id' => '9' . $id, 'type' => 'media']],
            ],
        ];
    }

    private static function module(int $id, string $title, int $position, array $lessonIds): array
    {
        return [
            'id' => (string) $id,
            'type' => 'modules',
            'attributes' => [
                'title' => $title, 'description' => '', 'position' => $position,
                'poster_image_url' => '', 'publishing_option' => 'published',
            ],
            'relationships' => [
                'course' => ['data' => ['id' => '2000101', 'type' => 'courses']],
                'lessons' => ['data' => array_map(fn($id) => ['id' => (string) $id, 'type' => 'lessons'], $lessonIds)],
            ],
        ];
    }

    public static function response(): array
    {
        return [
            'data' => [
                'id' => '2000101',
                'type' => 'courses',
                'attributes' => ['title' => 'Example 101 Course', 'description' => '', 'thumbnail_url' => null],
                'relationships' => [
                    'site' => ['data' => ['id' => '1', 'type' => 'sites']],
                    'offers' => ['data' => [['id' => '700', 'type' => 'offers']]],
                    'modules' => ['data' => [['id' => '20', 'type' => 'modules'], ['id' => '10', 'type' => 'modules']]],
                    'lessons' => ['data' => array_map(fn($id) => ['id' => (string) $id, 'type' => 'lessons'], [201, 101, 102, 200])],
                ],
            ],
            'included' => [
                self::lesson(201, 'Second module, second lesson', 1, 20, 'draft'),
                self::module(20, 'Second module', 1, [200, 201]),
                self::lesson(102, 'First module, second lesson', 1, 10),
                self::lesson(200, 'Second module, first lesson', 0, 20),
                self::module(10, 'First module', 0, [101, 102]),
                self::lesson(101, 'First module, first lesson', 0, 10),
            ],
            'links' => ['self' => 'https://api.kajabi.com/v1/courses/2000101?include=modules,lessons'],
        ];
    }

    private function kajabi(MockClient $mockClient): KajabiService
    {
        $kajabi = new KajabiService('test-client-id', 'test-client-secret', '111111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        return $kajabi;
    }

    public function test_structure_orders_modules_and_lessons(): void
    {
        $mockClient = new MockClient([GetCourseStructure::class => MockResponse::make(self::response())]);

        $structure = $this->kajabi($mockClient)->courses->structure(2000101);

        $this->assertInstanceOf(CourseStructure::class, $structure);
        $this->assertSame([10, 20], array_map(fn(Module $m) => $m->id, $structure->modules));
        $this->assertSame([101, 102, 200, 201], array_map(fn(Lesson $l) => $l->id, $structure->lessons));

        $this->assertSame('/courses/2000101', $mockClient->getLastPendingRequest()->getRequest()->resolveEndpoint());
        $this->assertSame('modules,lessons', $mockClient->getLastPendingRequest()->query()->get('include'));
    }

    public function test_module_and_lesson_fields(): void
    {
        $structure = CourseStructure::fromKajabiResponse(self::response());

        $module = $structure->modules[0];
        $this->assertSame('First module', $module->title);
        $this->assertSame(0, $module->position);
        $this->assertSame(2000101, $module->course_id);
        $this->assertSame([101, 102], $module->lesson_ids);
        $this->assertTrue($module->isPublished());

        $lesson = $structure->lesson(201);
        $this->assertSame('Second module, second lesson', $lesson->title);
        $this->assertSame(1, $lesson->position);
        $this->assertSame(20, $lesson->module_id);
        $this->assertSame('ready', $lesson->status);
        $this->assertFalse($lesson->isPublished(), 'draft');
        $this->assertNull($structure->lesson(999));

        $this->assertSame([200, 201], array_map(fn(Lesson $l) => $l->id, $structure->lessonsInModule(20)));
    }

    public function test_lesson_titles_for_labelling_progress(): void
    {
        $titles = CourseStructure::fromKajabiResponse(self::response())->lessonTitles();

        $this->assertSame('First module, first lesson', $titles[101]);
        $this->assertCount(4, $titles);
    }

    public function test_lessons_and_modules_shortcuts(): void
    {
        $mockClient = new MockClient([GetCourseStructure::class => MockResponse::make(self::response())]);
        $courses = $this->kajabi($mockClient)->courses;

        $this->assertSame([101, 102, 200, 201], array_map(fn(Lesson $l) => $l->id, $courses->lessons(2000101)));
        $this->assertSame([10, 20], array_map(fn(Module $m) => $m->id, $courses->modules(2000101)));
    }

    public function test_course_explicit_id_lists(): void
    {
        $course = CourseStructure::fromKajabiResponse(self::response())->course;

        $this->assertSame([20, 10], $course->module_ids, 'as Kajabi lists them');
        $this->assertSame([201, 101, 102, 200], $course->lesson_ids);
        $this->assertSame([2000101], $course->product_ids, 'a course id is its product id');
        $this->assertSame([20, 10], $course->chapter_ids, 'still module ids, for the contract');
        $this->assertNull($course->product_id, 'unchanged: no product relationship');
    }

    public function test_course_without_modules_uses_lessons_as_chapters(): void
    {
        $data = self::response()['data'];
        unset($data['relationships']['modules']);

        $course = Course::fromKajabiCourse($data);

        $this->assertSame([], $course->module_ids);
        $this->assertSame([201, 101, 102, 200], $course->chapter_ids);
    }

    public function test_offer_course_lists_products_not_modules(): void
    {
        $course = Course::fromKajabiOffer([
            'id' => '700',
            'type' => 'offers',
            'attributes' => ['title' => 'Bundle'],
            'relationships' => ['products' => ['data' => [['id' => '1', 'type' => 'products'], ['id' => '2', 'type' => 'products']]]],
        ]);

        $this->assertSame([1, 2], $course->product_ids);
        $this->assertSame([1, 2], $course->chapter_ids);
        $this->assertSame([], $course->module_ids);
        $this->assertSame([], $course->lesson_ids);
    }

    public function test_non_course_product_is_a_request_exception(): void
    {
        $mockClient = new MockClient([GetCourseStructure::class => MockResponse::make(
            ['errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'Could not find the specified resource.']]],
            404
        )]);

        $this->expectException(RequestException::class);

        $this->kajabi($mockClient)->courses->structure(2000202);
    }
}
