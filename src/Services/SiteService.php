<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Sites\Site;
use WooNinja\KajabiSaloon\Requests\Sites\GetSite;
use WooNinja\KajabiSaloon\Requests\Sites\GetSites;

class SiteService extends Resource
{
    public function get(int $site_id): Site
    {
        return $this->connector->send(new GetSite($site_id))->dtoOrFail();
    }

    public function sites(array $filters = []): Paginator
    {
        return $this->connector->paginate(new GetSites($filters));
    }
}
