<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Webhooks\Webhook;
use WooNinja\KajabiSaloon\Enums\WebhookEvent;
use WooNinja\KajabiSaloon\Requests\Webhooks\CreateWebhook;
use WooNinja\KajabiSaloon\Requests\Webhooks\DeleteWebhook;
use WooNinja\KajabiSaloon\Requests\Webhooks\GetWebhook;
use WooNinja\KajabiSaloon\Requests\Webhooks\GetWebhookSample;
use WooNinja\KajabiSaloon\Requests\Webhooks\GetWebhooks;
use WooNinja\KajabiSaloon\Webhooks\Events\KajabiWebhookEvent;
use WooNinja\KajabiSaloon\Webhooks\WebhookParser;

/**
 * Kajabi hooks (/v1/hooks, not /webhooks like Thinkific)
 *
 * Hooks can't be edited: use replace() to move one to a new URL.
 */
class WebhookService extends Resource
{
    public function get(int $webhook_id): Webhook
    {
        return $this->connector->send(new GetWebhook($webhook_id))->dtoOrFail();
    }

    /**
     * List hooks. Supports 'event' (=> filter[event_eq]) plus the usual paging filters.
     */
    public function webhooks(array $filters = []): Paginator
    {
        return $this->connector->paginate(new GetWebhooks($filters));
    }

    /**
     * Create a hook sending one event to one https:// URL
     *
     * @param int|string|null $courseId Course (product) id to scope lesson_completed,
     *                                  course_started or course_completed to one course
     * @param string|null $siteId Defaults to the service's site id
     * @throws \InvalidArgumentException on a non-https URL, an unscopable event with a
     *                                   course id, or no site id
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function create(
        WebhookEvent|string $event,
        string $targetUrl,
        int|string|null $courseId = null,
        ?string $siteId = null
    ): Webhook
    {
        $event = WebhookEvent::fromMixed($event);

        // Kajabi refuses http:// with a 422; fail before the round trip
        if (strtolower((string) parse_url($targetUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new \InvalidArgumentException("Kajabi hook target URLs must use https://, got: {$targetUrl}");
        }

        if ($courseId !== null && !$event->isCourseScopable()) {
            throw new \InvalidArgumentException("The {$event->value} event can't be scoped to a course");
        }

        $siteId = $siteId ?? $this->getDefaultSiteId();

        if ($siteId === null || $siteId === '') {
            throw new \InvalidArgumentException('A site_id is required to create a hook; pass one or set a default site id');
        }

        return $this->connector
            ->send(new CreateWebhook($event, $targetUrl, $siteId, $courseId))
            ->dtoOrFail();
    }

    /**
     * Delete a hook (204)
     *
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function delete(int $webhook_id): Response
    {
        return $this->connector->send(new DeleteWebhook($webhook_id));
    }

    /**
     * Point an existing hook at a new URL: creates the new hook first, then deletes the old one,
     * so there's no gap in deliveries (a brief overlap may deliver twice - dedupe on content).
     *
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function replace(Webhook|int $webhook, string $newTargetUrl): Webhook
    {
        $existing = $webhook instanceof Webhook ? $webhook : $this->get($webhook);

        $replacement = $this->create(
            $existing->event,
            $newTargetUrl,
            $existing->resource_id,
            $existing->site_id !== null ? (string) $existing->site_id : null
        );

        $this->delete($existing->id);

        return $replacement;
    }

    /**
     * Sample payload array for an event (GET /v1/hooks/{event}_sample)
     *
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function sample(WebhookEvent|string $event): array
    {
        return $this->connector
            ->send(new GetWebhookSample(WebhookEvent::fromMixed($event)))
            ->dtoOrFail();
    }

    /**
     * Fetch an event's sample and parse it into the same typed event a live delivery produces
     */
    public function sampleEvent(WebhookEvent|string $event): KajabiWebhookEvent
    {
        $event = WebhookEvent::fromMixed($event);

        return WebhookParser::parseSample($event, $this->sample($event));
    }

    /**
     * Parse an inbound delivery body into a typed event (see WebhookParser)
     */
    public function parse(string|array $body): KajabiWebhookEvent
    {
        return WebhookParser::parse($body);
    }
}
