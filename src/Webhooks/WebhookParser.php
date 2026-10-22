<?php

namespace WooNinja\KajabiSaloon\Webhooks;

use WooNinja\KajabiSaloon\Enums\WebhookEvent;
use WooNinja\KajabiSaloon\Exceptions\InvalidWebhookPayloadException;
use WooNinja\KajabiSaloon\Webhooks\Events\AssessmentCompleted;
use WooNinja\KajabiSaloon\Webhooks\Events\CourseCompleted;
use WooNinja\KajabiSaloon\Webhooks\Events\CourseStarted;
use WooNinja\KajabiSaloon\Webhooks\Events\GenericWebhookEvent;
use WooNinja\KajabiSaloon\Webhooks\Events\KajabiWebhookEvent;
use WooNinja\KajabiSaloon\Webhooks\Events\LessonCompleted;
use WooNinja\KajabiSaloon\Webhooks\Events\MemberSignedIn;

/**
 * Turns an inbound Kajabi webhook delivery into a typed event
 *
 * Framework agnostic: pass the raw request body or the decoded array.
 * Kajabi deliveries are not signed, so there is nothing to verify here; use a
 * hard-to-guess URL and confirm important actions against course progress.
 *
 * ```php
 * $event = WebhookParser::parse($request->getContent());
 * if ($event instanceof LessonCompleted) { ... }
 * ```
 */
final class WebhookParser
{
    /**
     * @throws InvalidWebhookPayloadException
     */
    public static function parse(string|array $body): KajabiWebhookEvent
    {
        $delivery = is_string($body) ? WebhookDelivery::fromJson($body) : WebhookDelivery::fromArray($body);

        return self::fromDelivery($delivery);
    }

    /**
     * Parse a sample payload (GET /v1/hooks/{event}_sample returns only the payload array)
     */
    public static function parseSample(WebhookEvent $event, array $payload, string $deliveryId = 'sample'): KajabiWebhookEvent
    {
        return self::fromDelivery(new WebhookDelivery($deliveryId, $event->value, array_values($payload)));
    }

    public static function fromDelivery(WebhookDelivery $delivery): KajabiWebhookEvent
    {
        return match ($delivery->eventType()) {
            WebhookEvent::LessonCompleted => new LessonCompleted($delivery),
            WebhookEvent::CourseStarted => new CourseStarted($delivery),
            WebhookEvent::CourseCompleted => new CourseCompleted($delivery),
            WebhookEvent::AssessmentCompleted => new AssessmentCompleted($delivery),
            WebhookEvent::MemberSignedIn => new MemberSignedIn($delivery),
            default => new GenericWebhookEvent($delivery),
        };
    }

    /**
     * Whether Kajabi will retry a delivery your endpoint answered with this status
     *
     * Only 429 is retried; 410 deletes the hook; any other failure is dropped
     * (rely on the scheduled course progress sync to catch up).
     */
    public static function isRetriedStatus(int $status): bool
    {
        return $status === 429;
    }
}
