<?php

namespace WooNinja\KajabiSaloon\Services;

use WooNinja\KajabiSaloon\Interfaces\Kajabi;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;

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
}
