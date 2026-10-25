<?php

namespace WooNinja\KajabiSaloon\Connectors;

use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
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
use Saloon\Traits\Plugins\HasTimeout;
use WooNinja\KajabiSaloon\Exceptions\UnsupportedFilterException;
use WooNinja\KajabiSaloon\Requests\Concerns\SupportedFilters;
use WooNinja\KajabiSaloon\Requests\Contracts\DeclaresSupportedFilters;

class KajabiConnector extends Connector implements HasPagination
{
    use AcceptsJson;
    use AlwaysThrowOnErrors;
    use HasTimeout;
    use HasRateLimits {
        handleExceededLimit as private traitHandleExceededLimit;
    }

    /**
     * Name of the shared client-side limit. Kajabi limits by client IP (at
     * Cloudflare), not by token or site, so every connector on one server should
     * share it - keep the default unless your sites send from different IPs.
     */
    public const DEFAULT_RATE_LIMIT_NAME = 'kajabi-api';

    /**
     * Kajabi's real limit, measured 2026-10-24 (not published by Kajabi): about
     * 1000 requests per sliding 60 seconds per client IP, enforced by
     * Cloudflare for every token and site. Going over returns 429 (text/plain
     * "error code: 1015", Retry-After: 600) and blocks the IP - token exchange
     * included - for 10 minutes.
     */
    public const KAJABI_IP_LIMIT_PER_MINUTE = 1000;

    /**
     * Seconds Kajabi blocks an IP after a 429
     */
    public const KAJABI_BLOCK_SECONDS = 600;

    /**
     * Client-side default. Deliberately a tenth of the IP limit: the default
     * MemoryStore counts per PHP process, so this stays safe with several
     * workers. With a shared store (setRateStore) across every process on the
     * IP, up to ~900 is reasonable.
     */
    public const DEFAULT_RATE_LIMIT = 100;

    public bool|RateLimitStore $rateStore = false;
    public int $rateLimit = self::DEFAULT_RATE_LIMIT; // requests per minute, client side
    public string $rateLimitName = self::DEFAULT_RATE_LIMIT_NAME;
    public bool $sleepOnRateLimit = false;
    public int $maxRateLimitSleepSeconds = 60;
    public bool $strictFilters = false;

    /**
     * Seconds. Without a timeout a stalled Kajabi response holds the worker
     * indefinitely (one was observed taking 300s before the socket gave up).
     * Large pages (page[size]=1000 on /customers) take a few seconds.
     */
    protected float $connectTimeout = 10;
    protected float $requestTimeout = 60;
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
     * Connection and whole-request timeouts in seconds (defaults 10 and 60).
     * A timeout throws Saloon\Exceptions\Request\FatalRequestException.
     */
    public function setTimeouts(float $connectSeconds, float $requestSeconds): void
    {
        $this->connectTimeout = $connectSeconds;
        $this->requestTimeout = $requestSeconds;
    }

    /**
     * Refuse to send list requests with filters/parameters Kajabi ignores
     *
     * Kajabi answers an unsupported filter (filter[id], filter[contact_id], a
     * typo...) with the UNFILTERED list and a 200, so code that relies on the
     * filter silently processes everything. In strict mode such a request
     * throws UnsupportedFilterException instead. Recommended in development
     * and tests; off by default for backward compatibility.
     */
    public function setStrictFilters(bool $strict = true): void
    {
        $this->strictFilters = $strict;
    }

    /**
     * @throws UnsupportedFilterException in strict filter mode
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        $request = $pendingRequest->getRequest();

        if (!$this->strictFilters || !$request instanceof DeclaresSupportedFilters) {
            return;
        }

        $ignored = SupportedFilters::ignored($pendingRequest->query()->all(), $request::supportedFilters());

        if ($ignored !== []) {
            throw new UnsupportedFilterException($request::class, $ignored);
        }
    }

    /**
     * Name the client-side limit (its bucket in the rate limit store)
     *
     * Connectors with the same name and store share one allowance. Only give
     * sites separate names when they really send from separate IPs.
     */
    public function setRateLimitName(string $name): void
    {
        $this->rateLimitName = $name;
    }

    /**
     * Wait instead of throwing when the client-side limit is reached
     *
     * NOT RECOMMENDED. The default - throwing RateLimitReachedException - is
     * standard practice: catch it and reschedule the work (e.g. release a
     * queued job with a delay of secondsUntilAvailable()). Sleeping holds a
     * worker and its memory while doing nothing, can outlast job and HTTP
     * timeouts, and hides back-pressure from the caller. Only use it for
     * one-off scripts or CLI commands where blocking is acceptable.
     *
     * Waits up to $maxSeconds; a longer wait - e.g. after Kajabi answered 429,
     * which blocks the IP for Retry-After (600s) - still throws.
     */
    public function setSleepOnRateLimit(bool $sleep = true, ?int $maxSeconds = null): void
    {
        $this->sleepOnRateLimit = $sleep;

        if ($maxSeconds !== null) {
            $this->maxRateLimitSleepSeconds = max(0, $maxSeconds);
        }
    }

    /**
     * Seconds until a request would be allowed again (0 = now), e.g. to delay a queued job
     */
    public function secondsUntilAvailable(): int
    {
        $limit = $this->getExceededLimit();

        return $limit instanceof Limit ? max(0, $limit->getRemainingSeconds()) : 0;
    }

    /**
     * Rate limits for Kajabi
     *
     * The plugin also records Kajabi's own 429s (too_many_attempts_limit) for
     * their Retry-After, so no request is sent while the IP is blocked -
     * requests during a block risk extending it.
     *
     * @return array
     */
    protected function resolveLimits(): array
    {
        $limit = Limit::allow(requests: $this->rateLimit, threshold: 0.99)
            ->everyMinute()
            ->name($this->rateLimitName);

        return [$this->sleepOnRateLimit ? $limit->sleep() : $limit];
    }

    /**
     * A fixed prefix, so subclasses (named differently) still share the bucket.
     * The plugin's default is the class's short name.
     */
    protected function getLimiterPrefix(): ?string
    {
        return 'kajabi';
    }

    /**
     * Sleep only for short waits; anything longer throws
     */
    protected function handleExceededLimit(Limit $limit, PendingRequest $pendingRequest): void
    {
        if ($limit->getShouldSleep() && $limit->getRemainingSeconds() > $this->maxRateLimitSleepSeconds) {
            $this->throwLimitException($limit);
        }

        $this->traitHandleExceededLimit($limit, $pendingRequest);
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
        $filters = $request->query()->all();

        // Check for start page - Kajabi 'page[number]' or Thinkific 'page' or 'start_page'
        $startPage = $filters['page[number]'] ?? $filters['page'] ?? $filters['start_page'] ?? 1;

        // Check for page limit - Kajabi 'page[size]' or Thinkific 'limit'
        $pageLimit = $filters['page[size]'] ?? $filters['limit'] ?? null;

        // Paging controls are read here, and Kajabi ignores them, so they aren't
        // sent: limit/page become page[size]/page[number]. This has to happen
        // before the paginator is built - it keeps a copy of the request.
        foreach (['limit', 'page', 'start_page', 'max_pages'] as $control) {
            $request->query()->remove($control);
        }
        if (is_numeric($pageLimit) && $request->query()->get('page[size]') === null) {
            $request->query()->add('page[size]', (int) $pageLimit);
        }
        if ((int) $startPage > 1 && $request->query()->get('page[number]') === null) {
            $request->query()->add('page[number]', (int) $startPage);
        }

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

            /**
             * meta.total - only /contacts and /customers send it (verified live);
             * other lists answer 0. Check hasTotalAPIResults() first.
             */
            public function getTotalAPIResults(): int
            {
                return (int) $this->currentResponse->json('meta.total', 0);
            }

            public function hasTotalAPIResults(): bool
            {
                return $this->currentResponse->json('meta.total') !== null;
            }

            /**
             * From meta.total where sent, else from the page[number] in links.last
             * (offers, products, orders, purchases... send links but no meta)
             */
            public function getTotalAPIPages(): int
            {
                $total = $this->currentResponse->json('meta.total');

                if ($total !== null) {
                    return $total > 0 ? (int) ceil($total / ($this->perPageLimit ?? 100)) : 1;
                }

                $last = $this->currentResponse->json('links.last');

                if (is_string($last)) {
                    parse_str((string) parse_url($last, PHP_URL_QUERY), $query);

                    if (isset($query['page']['number'])) {
                        return max(1, (int) $query['page']['number']);
                    }
                }

                return 1;
            }

            /**
             * links.next of the page last fetched, or null on the last page / before the first
             *
             * For cursor-paged endpoints (course progress) this is where page[after]
             * lives: GetCourseProgress::cursorFrom($paginator->nextLink()).
             */
            public function nextLink(): ?string
            {
                $next = $this->currentResponse?->json('links.next');

                return is_string($next) && $next !== '' ? $next : null;
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

                            // Add all query params from next URL to request. parse_str nests
                            // "page[after]" as ['page' => ['after' => ...]]; flatten back to
                            // bracket keys so they replace (not duplicate) the existing ones.
                            foreach ($queryParams as $key => $value) {
                                if (is_array($value)) {
                                    foreach ($value as $subKey => $subValue) {
                                        $request->query()->add("{$key}[{$subKey}]", $subValue);
                                    }
                                    continue;
                                }

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

        $paginator->setStartPage($startPage);

        if ($pageLimit !== null) {
            $paginator->setPerPageLimit($pageLimit);
        }

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
