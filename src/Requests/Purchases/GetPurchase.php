<?php

namespace WooNinja\KajabiSaloon\Requests\Purchases;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;

class GetPurchase extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private int $purchaseId
    ) {}

    public function resolveEndpoint(): string
    {
        return "/purchases/{$this->purchaseId}";
    }

    protected function defaultQuery(): array
    {
        return [
            // Include offer and customer relationships to get course and user data
            // This avoids extra API calls
            'include' => 'offer,customer',
        ];
    }

    public function createDtoFromResponse($response): Enrollment
    {
        $data = $response->json('data');
        $included = $response->json('included', []);

        // Create a lookup map for included resources (offers)
        $includedMap = [];
        foreach ($included as $resource) {
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            if ($type && $id) {
                $includedMap["{$type}:{$id}"] = $resource;
            }
        }

        return Enrollment::fromKajabiPurchase($data, $includedMap);
    }
}
