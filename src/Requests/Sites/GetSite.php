<?php

namespace WooNinja\KajabiSaloon\Requests\Sites;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Sites\Site;

class GetSite extends Request
{
    protected Method $method = Method::GET;
    
    public function __construct(private int $siteId) {}
    
    public function resolveEndpoint(): string { return "/sites/{$this->siteId}"; }
    
    public function createDtoFromResponse($response): Site
    {
        return Site::fromKajabiSite($response->json('data'));
    }
}
