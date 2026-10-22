<?php

namespace WooNinja\KajabiSaloon\Requests\CourseProgress;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use Throwable;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgress;
use WooNinja\KajabiSaloon\Exceptions\FeatureNotEnabledException;

/**
 * GET /v1/course_progress
 *
 * Cursor paged: follow links.next (page[after]). page[number] is rejected with
 * a 400, so Thinkific-style page/start_page filters are dropped. There is no
 * total count.
 */
class GetCourseProgress extends Request implements Paginatable
{
    public const MAX_PAGE_SIZE = 100;

    protected Method $method = Method::GET;

    /**
     * @param int $productId The course (product) id - one course per request
     * @param array $filters customer_id, updated_since (DateTimeInterface|string),
     *                       limit|page[size] (max 100), after|page[after]
     */
    public function __construct(
        private int $productId,
        private array $filters = []
    ) {}

    public function resolveEndpoint(): string
    {
        return '/course_progress';
    }

    protected function defaultQuery(): array
    {
        $query = ['filter[product_id]' => $this->productId];

        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'customer_id':
                case 'user_id':
                case 'filter[customer_id]':
                    $query['filter[customer_id]'] = $value;
                    break;
                case 'updated_since':
                case 'filter[updated_since]':
                    $query['filter[updated_since]'] = self::formatTimestamp($value);
                    break;
                case 'limit':
                case 'page[size]':
                    $query['page[size]'] = min((int) $value, self::MAX_PAGE_SIZE);
                    break;
                case 'after':
                case 'page[after]':
                    $query['page[after]'] = $value;
                    break;
                // page[number] returns 400 and sort is ignored; paging controls are the paginator's
                default:
                    break;
            }
        }

        return $query;
    }

    /**
     * ISO 8601 with offset; a DateTimeInterface keeps its own timezone offset
     */
    public static function formatTimestamp(\DateTimeInterface|string $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : $value;
    }

    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        if ($response->status() === 423) {
            return new FeatureNotEnabledException($response, 'course progress');
        }

        return null;
    }

    public function createDtoFromResponse($response): array
    {
        return array_map(
            fn(array $row) => CourseProgress::fromKajabiCourseProgress($row, $this->productId),
            $response->json('data', [])
        );
    }
}
