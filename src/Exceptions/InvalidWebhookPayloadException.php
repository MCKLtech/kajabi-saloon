<?php

namespace WooNinja\KajabiSaloon\Exceptions;

/**
 * Thrown when an inbound webhook delivery body can't be parsed into an event
 */
class InvalidWebhookPayloadException extends \InvalidArgumentException
{
}
