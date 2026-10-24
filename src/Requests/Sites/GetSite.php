<?php

namespace WooNinja\KajabiSaloon\Requests\Sites;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Sites\Site;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;

class GetSite extends Request
{
    use JsonApiQuery;

    protected Method $method = Method::GET;
    
    public function __construct(private int $siteId) {}
    
    public function resolveEndpoint(): string { return "/sites/{$this->siteId}"; }
    
    public function createDtoFromResponse($response): Site
    {
        return Site::fromKajabiSite($response->json('data'));
    }
}
