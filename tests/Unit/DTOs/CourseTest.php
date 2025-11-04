<?php

namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\LMSContracts\Contracts\DTOs\Courses\CourseInterface;
use Tests\Fixtures\KajabiApiResponses;

class CourseTest extends TestCase
{
    public function test_implements_course_interface(): void
    {
        $courseData = KajabiApiResponses::course();
        $course = Course::fromResponse($courseData);

        $this->assertInstanceOf(CourseInterface::class, $course);
    }

    public function test_creates_course_from_kajabi_response(): void
    {
        $courseData = KajabiApiResponses::course();
        $course = Course::fromResponse($courseData);

        $this->assertEquals(202, $course->id);
        $this->assertEquals('Introduction to Marketing', $course->name);
        $this->assertEquals('Learn the basics of marketing', $course->description);
        $this->assertEquals('intro-to-marketing', $course->slug);
    }

    public function test_extracts_product_id_from_relationships(): void
    {
        $courseData = KajabiApiResponses::course();
        $course = Course::fromResponse($courseData);

        $this->assertEquals(303, $course->product_id);
    }

    public function test_handles_missing_product_relationship(): void
    {
        $courseData = KajabiApiResponses::course();
        unset($courseData['relationships']['product']);

        $course = Course::fromResponse($courseData);

        $this->assertNull($course->product_id);
    }

    public function test_preserves_timestamps(): void
    {
        $courseData = KajabiApiResponses::course();
        $course = Course::fromResponse($courseData);

        $this->assertEquals('2024-01-01T00:00:00Z', $course->created_at);
        $this->assertEquals('2024-01-10T12:00:00Z', $course->updated_at);
    }
}
