<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Modules;

/**
 * Course module (type "modules"), from GET /v1/courses/{id}?include=modules
 *
 * Positions are integers starting at 0 (the spec says string; the live API
 * sends ints).
 */
final class Module
{
    /**
     * @param int[] $lesson_ids In the order Kajabi lists them
     */
    public function __construct(
        public int     $id,
        public string  $title,
        public ?string $description,
        public ?int    $position,
        public ?string $poster_image_url,
        public ?string $publishing_option,
        public ?int    $course_id,
        public array   $lesson_ids = [],
    )
    {
    }

    public static function fromKajabiModule(array $module): self
    {
        $attributes = $module['attributes'] ?? [];
        $courseId = $module['relationships']['course']['data']['id'] ?? null;

        return new self(
            id: (int) $module['id'],
            title: $attributes['title'] ?? '',
            description: $attributes['description'] ?? null,
            position: isset($attributes['position']) ? (int) $attributes['position'] : null,
            poster_image_url: $attributes['poster_image_url'] ?? null,
            publishing_option: $attributes['publishing_option'] ?? null,
            course_id: $courseId !== null ? (int) $courseId : null,
            lesson_ids: array_map(
                fn(array $lesson) => (int) $lesson['id'],
                $module['relationships']['lessons']['data'] ?? []
            ),
        );
    }

    public function isPublished(): bool
    {
        return $this->publishing_option === 'published';
    }
}
