<?php

namespace WooNinja\KajabiSaloon\Requests\CourseProgress;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use Throwable;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgress;
use WooNinja\KajabiSaloon\Exceptions\CourseProgressForbiddenException;
use WooNinja\KajabiSaloon\Exceptions\CourseProgressNotFoundException;
use WooNinja\KajabiSaloon\Exceptions\FeatureNotEnabledException;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;
use WooNinja\KajabiSaloon\Requests\Concerns\SupportedFilters;
use WooNinja\KajabiSaloon\Requests\Contracts\DeclaresSupportedFilters;

/**
 * GET /v1/course_progress
 *
 * Cursor paged: follow links.next (page[after]). page[number] is rejected with
 * a 400, so Thinkific-style page/start_page filters are dropped. There is no
 * total count.
 */
class GetCourseProgress extends Request implements Paginatable, DeclaresSupportedFilters
{
    use JsonApiQuery;

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
                // Read (and stripped) by KajabiConnector::paginate(). page/start_page
                // stay dropped: they'd become page[number], which is a 400 here.
                case 'max_pages':
                    $query[$key] = $value;
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

    /**
     * The page[after] cursor in a links.next URL, or null on the last page
     *
     * Kajabi sends the URL with its brackets percent-encoded
     * (page%5Bafter%5D=...); both forms are read.
     */
    public static function cursorFrom(?string $nextLink): ?string
    {
        if ($nextLink === null || $nextLink === '') {
            return null;
        }

        parse_str((string) parse_url($nextLink, PHP_URL_QUERY), $query);
        $after = $query['page']['after'] ?? null;

        return $after !== null && $after !== '' ? (string) $after : null;
    }

    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        return match ($response->status()) {
            403 => new CourseProgressForbiddenException($response, $this->productId),
            404 => new CourseProgressNotFoundException($response, $this->productId),
            423 => new FeatureNotEnabledException($response, 'course progress'),
            default => null,
        };
    }

    public function createDtoFromResponse($response): array
    {
        return array_map(
            fn(array $row) => CourseProgress::fromKajabiCourseProgress($row, $this->productId),
            $response->json('data', [])
        );
    }

    public static function supportedFilters(): array
    {
        return SupportedFilters::COURSE_PROGRESS;
    }
}
