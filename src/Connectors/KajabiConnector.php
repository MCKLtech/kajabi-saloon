<?php

namespace WooNinja\KajabiSaloon\Connectors;

use ReflectionClass;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\HasPagination;
use Saloon\PaginationPlugin\CursorPaginator;
use Saloon\PaginationPlugin\Paginator;
use Saloon\Http\Response;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Stores\MemoryStore;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Saloon\Config;

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
            protected ?int $perPageLimit = 100;
            private int $pagesReturned = 0;
            protected int|null $maxPages = null;
            protected int $totalResults = 0;

            /**
             * Get the next cursor (next page URL) from response
             *
             * @param Response $response
             * @return int|string
             */
            protected function getNextCursor(Response $response): int|string
            {
                // Check if we've reached max pages limit
                if ($this->maxPages !== null && $this->pagesReturned >= $this->maxPages) {
                    return '';
                }

                // Get next link from response
                // Response: "links": { "next": "https://api.kajabi.com/v1/contacts?page[number]=2&page[size]=2" }
                $nextLink = $response->json('links.next');

                // No next link means we're on the last page
                return $nextLink ?? '';
            }

            /**
             * Check if we've reached the last page
             *
             * @param Response $response
             * @return bool
             */
            protected function isLastPage(Response $response): bool
            {
                // Check max pages limit
                if ($this->maxPages !== null && $this->pagesReturned >= $this->maxPages) {
                    return true;
                }

                // No "next" link in response means last page
                return !$response->json('links.next');
            }

            /**
             * Extract items from response and transform to DTOs
             *
             * @param Response $response
             * @param Request $request
             * @return array
             */
            protected function getPageItems(Response $response, Request $request): array
            {
                // Cache check to avoid double API calls
                $cacheKey = spl_object_id($response);

                if (isset($this->pageItemsKey) && $this->pageItemsKey === $cacheKey) {
                    return $this->pageItems;
                }

                $this->pageItemsKey = $cacheKey;

                // Transform response data to DTOs
                $this->pageItems = $response->dtoOrFail();

                // Store total from meta (default to 0 if not present)
                $this->totalResults = $response->json('meta.total') ?? 0;

                // Increment page counter
                $this->pagesReturned++;

                return $this->pageItems;
            }

            /**
             * Apply pagination to request
             *
             * First request: Uses page[size] from filters
             * Subsequent requests: Uses full URL from links.next
             *
             * @param Request $request
             * @return Request
             */
            protected function applyPagination(Request $request): Request
            {
                // If we have a response (not first request), use next link
                if ($this->currentResponse instanceof Response) {
                    $nextUrl = $this->getNextCursor($this->currentResponse);

                    if ($nextUrl && $nextUrl !== '') {
                        // Parse next URL and extract query parameters
                        $parsedUrl = parse_url($nextUrl);

                        if (isset($parsedUrl['query'])) {
                            parse_str($parsedUrl['query'], $queryParams);

                            // Clear existing pagination params and add from next URL
                            foreach ($queryParams as $key => $value) {
                                $request->query()->add($key, $value);
                            }
                        }
                    }
                } else {
                    // First request: add page[size] parameter
                    if ($this->perPageLimit !== null) {
                        $request->query()->add('page[size]', $this->perPageLimit);
                    }
                }

                return $request;
            }

            /**
             * Get total number of results from meta.total
             *
             * @return int
             */
            public function getTotalResults(): int
            {
                return $this->totalResults;
            }

            /**
             * Set maximum number of pages to fetch
             *
             * @param int|null $maxPages
             * @return Paginator
             */
            public function setMaxPages(int|null $maxPages): Paginator
            {
                $this->maxPages = $maxPages;
                return $this;
            }

        };

        // Extract filters from request query
        $filters = $request->query()->all();

        // Set page size (items per page)
        $pageSize = $filters['limit'] ?? 100;
        $paginator->setPerPageLimit($pageSize);

        // Set maximum pages to fetch (default: null = all pages)
        if (isset($filters['max_pages'])) {
            $paginator->setMaxPages($filters['max_pages']);
        }

        return $paginator;
    }
}
