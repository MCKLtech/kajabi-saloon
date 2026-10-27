<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;
use WooNinja\KajabiSaloon\Interfaces\Kajabi;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOfferIds;
use WooNinja\KajabiSaloon\Requests\Offers\RevokeOffer;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UserInterface;

abstract class Resource
{
    protected KajabiConnector $connector;
    protected KajabiService $kajabi;

    public function __construct(Kajabi $kajabi)
    {
        // Store reference to KajabiService for accessing site_id
        $this->kajabi = $kajabi;
        $this->connector = $kajabi->connector();
    }

    /**
     * Get the default site_id from the KajabiService
     *
     * @return string|null
     */
    protected function getDefaultSiteId(): ?string
    {
        return $this->kajabi->getSiteId();
    }

    /**
     * "kajabi:{site}:{$name}", so sites sharing a cache store don't collide
     */
    protected function cacheKey(string $name): string
    {
        return 'kajabi:' . ($this->getDefaultSiteId() ?? 'default') . ':' . $name;
    }

    /**
     * A Contact by id (throws when Kajabi can't find it) or by email (null when no exact match)
     */
    protected function resolveContact(int|string $user_id_or_email): ?UserInterface
    {
        $userService = new UserService($this->kajabi);

        return is_numeric($user_id_or_email)
            ? $userService->get((int) $user_id_or_email)
            : $userService->findByEmail((string) $user_id_or_email);
    }

    /**
     * Revoke an offer from a contact, idempotently
     *
     * Kajabi answers 400 "Cannot remove relationships" when the contact doesn't
     * hold the offer (verified live). The contact's offers are then re-read:
     * if the offer really isn't held, that (successful) response is returned,
     * so expiring twice is harmless. Any other error - or that 400 while the
     * offer is still held - is rethrown.
     *
     * @throws RequestException
     */
    protected function revokeOffer(int $contactId, int $offerId): Response
    {
        try {
            return $this->connector->send(
                new RevokeOffer((string) $contactId, (string) $offerId, $this->getDefaultSiteId())
            );
        } catch (RequestException $e) {
            if ($e->getResponse()->status() !== 400
                || $e->getResponse()->json('errors.0.detail') !== 'Cannot remove relationships') {
                throw $e;
            }

            $held = $this->connector->send(new GetContactOfferIds($contactId));

            if (in_array($offerId, $held->dtoOrFail(), true)) {
                throw $e;
            }

            return $held;
        }
    }
}
