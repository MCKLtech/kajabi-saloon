<?php

namespace WooNinja\KajabiSaloon\Webhooks\Events;

/**
 * member_signed_in: best-effort activity signal, not a login audit log
 *
 * Also fires on automatic sign-ins (post-checkout, remember-me, SSO, invite
 * links, mobile app refresh) and may be dropped without retry.
 */
final class MemberSignedIn extends CustomerActivityEvent
{
    protected static function metaTimeKey(): string
    {
        return 'signed_in_at';
    }

    public function contentKey(): string
    {
        return self::key($this->eventName(), (string) $this->customerId(), $this->rawOccurredAt());
    }
}
