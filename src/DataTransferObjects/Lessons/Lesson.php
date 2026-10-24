<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Lessons;

/**
 * Lesson (type "lessons"), from member activity webhook payloads and from
 * GET /v1/courses/{id}?include=lessons
 *
 * $position orders lessons within their module and starts at 0. $status is
 * the processing state ("ready", "duplicating"); visibility is
 * $publishing_option (published, draft, drip, locked).
 */
final class Lesson
{
    public function __construct(
        public int     $id,
        public string  $title,
        public ?int    $position,
        public ?string $status,
        public ?string $publishing_option,
        public ?int    $module_id,
        public ?int    $media_id = null,
    )
    {
    }

    public static function fromKajabiLesson(array $lesson): self
    {
        $moduleId = $lesson['relationships']['module']['data']['id'] ?? null;
        $mediaId = $lesson['relationships']['media']['data']['id'] ?? null;

        return new self(
            id: (int) $lesson['id'],
            title: $lesson['attributes']['title'] ?? '',
            position: isset($lesson['attributes']['position']) ? (int) $lesson['attributes']['position'] : null,
            status: $lesson['attributes']['status'] ?? null,
            publishing_option: $lesson['attributes']['publishing_option'] ?? null,
            module_id: $moduleId !== null ? (int) $moduleId : null,
            media_id: $mediaId !== null ? (int) $mediaId : null,
        );
    }

    public function isPublished(): bool
    {
        return $this->publishing_option === 'published';
    }
}
