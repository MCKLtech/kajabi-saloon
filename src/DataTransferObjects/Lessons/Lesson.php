<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Lessons;

/**
 * Lesson as sent in member activity webhook payloads (type "lessons")
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
}
