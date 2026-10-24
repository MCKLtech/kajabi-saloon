<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Courses;

use WooNinja\KajabiSaloon\DataTransferObjects\Lessons\Lesson;
use WooNinja\KajabiSaloon\DataTransferObjects\Modules\Module;

/**
 * A course's modules and lessons, from one GET /v1/courses/{id}?include=modules,lessons
 *
 * Kajabi has no lesson or module endpoints; this include is the only source.
 * Modules are ordered by position; lessons by their module's position, then
 * their own. The course id is the product id.
 */
final class CourseStructure
{
    /** @var array<int, Lesson> */
    private array $lessonsById = [];

    /**
     * @param Module[] $modules
     * @param Lesson[] $lessons
     */
    public function __construct(
        public readonly Course $course,
        public readonly array  $modules,
        public readonly array  $lessons,
    )
    {
        foreach ($lessons as $lesson) {
            $this->lessonsById[$lesson->id] = $lesson;
        }
    }

    public static function fromKajabiResponse(array $body): self
    {
        $modules = [];
        $lessons = [];

        foreach ($body['included'] ?? [] as $resource) {
            match ($resource['type'] ?? null) {
                'modules' => $modules[] = Module::fromKajabiModule($resource),
                'lessons' => $lessons[] = Lesson::fromKajabiLesson($resource),
                default => null,
            };
        }

        usort($modules, fn(Module $a, Module $b) => [$a->position ?? PHP_INT_MAX, $a->id] <=> [$b->position ?? PHP_INT_MAX, $b->id]);

        $moduleOrder = [];
        foreach ($modules as $index => $module) {
            $moduleOrder[$module->id] = $index;
        }

        usort($lessons, fn(Lesson $a, Lesson $b) => [
            $moduleOrder[$a->module_id] ?? PHP_INT_MAX, $a->position ?? PHP_INT_MAX, $a->id,
        ] <=> [
            $moduleOrder[$b->module_id] ?? PHP_INT_MAX, $b->position ?? PHP_INT_MAX, $b->id,
        ]);

        return new self(Course::fromKajabiCourse($body['data']), $modules, $lessons);
    }

    public function lesson(int $lessonId): ?Lesson
    {
        return $this->lessonsById[$lessonId] ?? null;
    }

    /**
     * @return Lesson[] In order
     */
    public function lessonsInModule(int $moduleId): array
    {
        return array_values(array_filter($this->lessons, fn(Lesson $lesson) => $lesson->module_id === $moduleId));
    }

    /**
     * lesson_id => title, e.g. to label course progress rows (which carry only lesson ids)
     *
     * @return array<int, string>
     */
    public function lessonTitles(): array
    {
        return array_map(fn(Lesson $lesson) => $lesson->title, $this->lessonsById);
    }
}
