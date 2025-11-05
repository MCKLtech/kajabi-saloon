<?php

namespace WooNinja\KajabiSaloon\Connectors;

use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\HasPagination;
use Saloon\PaginationPlugin\CursorPaginator;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Traits\HasAsyncPagination;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Stores\MemoryStore;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class KajabiConnector extends Connector implements HasPagination
{
    use AcceptsJson;
    use AlwaysThrowOnErrors;
    use HasRateLimits;

    public bool|RateLimitStore $rateStore = false;
    public int $rateLimit = 100; // Kajabi's default rate limit
    public string $baseUrl = 'https://api.kajabi.com/v1';

    public function __construct()
    {
        // Kajabi doesn't use subdomain like Thinkific
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => 'WooNinja/Kajabi-Saloon-PHP-SDK',
            'Accept' => 'application/vnd.api+json'
        ];
    }

    protected function defaultConfig(): array
    {
        return [];
    }

    /**
     * Dynamically set the RateLimit Store
     *
     * @param RateLimitStore $store
     * @return void
     */
    public function setRateStore(RateLimitStore $store): void
    {
        $this->rateStore = $store;
    }

    /**
     * Dynamically set the rate limit
     *
     * @param int $limit
     * @return void
     */
    public function setRateLimit(int $limit): void
    {
        $this->rateLimit = $limit;
    }

    /**
     * Rate limits for Kajabi
     *
     * @return array
     */
    protected function resolveLimits(): array
    {
        return [
            Limit::allow(requests: $this->rateLimit, threshold: 0.99)
                ->everyMinute()
                ->name('kajabi-api')
        ];
    }

    /**
     * The Rate Limit Store for Saloon
     *
     * @return RateLimitStore
     */
    protected function resolveRateLimitStore(): RateLimitStore
    {
        if ($this->rateStore) return $this->rateStore;

        return new MemoryStore();
    }

    /**
     * Pagination configuration for Kajabi (JSON:API format)
     *
     * Kajabi Response Format:
     * {
     *   "data": [...],
     *   "meta": {
     *     "total": 7855
     *   },
     *   "links": {
     *     "self": "https://api.kajabi.com/v1/contacts?page[number]=1&page[size]=2",
     *     "current": "https://api.kajabi.com/v1/contacts?page[number]=1&page[size]=2",
     *     "next": "https://api.kajabi.com/v1/contacts?page[number]=2&page[size]=2",
     *     "last": "https://api.kajabi.com/v1/contacts?page[number]=3928&page[size]=2"
     *   }
     * }
     *
     * Navigation:
     * - Uses links.next for pagination
     * - No "next" link means last page
     * - meta.total gives total record count
     *
     * Filter options:
     * - limit: Items per page (default: 100)
     * - max_pages: Maximum pages to fetch (default: null = all pages)
     *
     * @param Request $request
     * @return CursorPaginator
     */
    public function paginate(Request $request): CursorPaginator
    {
        $paginator = new class(connector: $this, request: $request) extends CursorPaginator {
            private int $pageItemsKey;
            private array $pageItems;
            protected ?int $perPageLimit = 50;

            /**
             * Override count() to use async to avoid looping all pages
             *
             * @return int
             */
            public function count() : int
            {
                $this->async();

                $count = parent::count();

                $this->async(false);

                return $count;
            }

            public function getPerPageLimit(): int
            {
                return $this->perPageLimit;
            }

            public function getTotalAPIResults(): int
            {
                return $this->currentResponse->json('meta.total', 0);
            }

            public function getTotalAPIPages(): int
            {
                $total = $this->currentResponse->json('meta.total', 0);

                if ($total === 0) {
                    return 1;
                }

                // Calculate from total records and page size
                $pageSize = $this->perPageLimit ?? 100;

                return (int)ceil($total / $pageSize);
            }

            protected function getNextCursor(Response $response): int|string
            {
                return $response->json('links.next');
            }

            protected function isLastPage(Response $response): bool
            {
                return empty($response->json('links.next'));
            }

            protected function getPageItems(Response $response, Request $request): array
            {
                /**
                 * This is a workaround to avoid a double API call when using the paginator.
                 * @see https://github.com/saloonphp/saloon/discussions/449
                 */
                $cacheKey = spl_object_id($response);

                if (isset($this->pageItemsKey) && $this->pageItemsKey === $cacheKey) {
                    return $this->pageItems;
                }

                $this->pageItemsKey = $cacheKey;
                $this->pageItems = $response->dtoOrFail();
                return $this->pageItems;
            }

            protected function getTotalPages(Response $response): int
            {
                return $this->getTotalAPIPages();
            }

            protected function applyPagination(Request $request): Request
            {
                // If we have a response (not first request), use next link URL
                if ($this->currentResponse instanceof Response) {
                    $nextUrl = $this->getNextCursor($this->currentResponse);

                    if ($nextUrl && $nextUrl !== '') {
                        // Parse next URL and extract query parameters
                        $parsedUrl = parse_url($nextUrl);

                        if (isset($parsedUrl['query'])) {
                            parse_str($parsedUrl['query'], $queryParams);

                            // Add all query params from next URL to request
                            foreach ($queryParams as $key => $value) {
                                $request->query()->add($key, $value);
                            }
                        }
                    }
                } else {
                    // First request: read filters and apply pagination
                    $filters = $request->query()->all();

                    // Set per page limit - check both 'limit' (Thinkific) and 'page[size]' (Kajabi)
                    $limit = $filters['limit'] ?? $filters['page[size]'] ?? $this->perPageLimit;
                    $this->setPerPageLimit($limit);

                    // Add page[size] parameter for Kajabi API
                    if (is_numeric($this->perPageLimit)) {
                        $request->query()->add('page[size]', $this->perPageLimit);
                    }

                    // Add page[number] for first request - check both 'page' (Thinkific) and 'page[number]' (Kajabi)
                    $pageNumber = $filters['page'] ?? $filters['page[number]'] ?? $filters['start_page'] ?? null;
                    if ($pageNumber) {
                        $request->query()->add('page[number]', $pageNumber);
                    }
                }

                return $request;
            }

        };

        $filters = $request->query()->all();

        // Check for start page - Kajabi 'page[number]' or Thinkific 'page' or 'start_page'
        $startPage = $filters['page[number]'] ?? $filters['page'] ?? $filters['start_page'] ?? 1;
        $paginator->setStartPage($startPage);

        // Check for page limit - Kajabi 'page[size]' or Thinkific 'limit'
        $pageLimit = $filters['page[size]'] ?? $filters['limit'] ?? $paginator->getPerPageLimit() ?? 100;
        $paginator->setPerPageLimit($pageLimit);

        $paginator->rewind();

        if (isset($filters['max_pages'])) {

            /**
             * We add on the max_pages otherwise we may already be at the 'max' page
             */
            $currentPage = $paginator->getCurrentPage();

            $paginator->setMaxPages($currentPage + $filters['max_pages']);

            /**
             * One good rewind deserves another
             */
            $paginator->rewind();
        }

        return $paginator;

    }
}
