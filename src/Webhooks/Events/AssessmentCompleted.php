<?php

namespace WooNinja\KajabiSaloon\Webhooks\Events;

use Carbon\Carbon;
use WooNinja\KajabiSaloon\DataTransferObjects\Assessments\Assessment;
use WooNinja\KajabiSaloon\DataTransferObjects\Assessments\AssessmentResult;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;
use WooNinja\KajabiSaloon\Webhooks\WebhookDelivery;

/**
 * assessment_completed: someone submitted an assessment (fires once)
 *
 * Unlike the other member events, the person is a "contacts" resource, mapped
 * to the SDK's User DTO. A passing (or ungraded) submission in a lesson also
 * marks the lesson complete, so lesson_completed may follow.
 */
final class AssessmentCompleted extends KajabiWebhookEvent
{
    public readonly User $contact;
    public readonly Assessment $assessment;
    public readonly AssessmentResult $result;

    public function __construct(WebhookDelivery $delivery)
    {
        parent::__construct($delivery);

        $this->contact = User::fromKajabiContact($delivery->requireResource('contacts'));
        $this->assessment = Assessment::fromKajabiAssessment($delivery->requireResource('assessments'));
        $this->result = AssessmentResult::fromKajabiAssessmentResult($delivery->requireResource('assessment_results'));
    }

    /**
     * Contact ID (the SDK's user_id)
     */
    public function contactId(): int
    {
        return $this->contact->id;
    }

    /**
     * Customer ID when the contact is a member, otherwise null
     */
    public function customerId(): ?int
    {
        return $this->contact->customer_id;
    }

    public function occurredAt(): ?Carbon
    {
        return $this->result->submitted_at;
    }

    public function contentKey(): string
    {
        return self::key($this->eventName(), (string) $this->result->id);
    }
}
