<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Offers\Offer;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffers;

class OfferService extends Resource
{
    public function get(int $offer_id): Offer
    {
        return $this->connector->send(new GetOffer($offer_id))->dtoOrFail();
    }

    public function offers(array $filters = []): Paginator
    {
        return $this->connector->paginate(new GetOffers($filters, $this->getDefaultSiteId()));
    }
}
