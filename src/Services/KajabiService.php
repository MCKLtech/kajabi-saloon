<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Contracts\Authenticator;
use WooNinja\KajabiSaloon\Auth\AccessToken;
use WooNinja\KajabiSaloon\Auth\KajabiAuthenticator;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Interfaces\Kajabi;
use WooNinja\KajabiSaloon\Requests\Me\GetMe;
use WooNinja\LMSContracts\Contracts\LMSServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\UserServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\CourseServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\EnrollmentServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\ProductServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\OrderServiceInterface;
use WooNinja\KajabiSaloon\Traits\MapperTrait;

final class KajabiService implements Kajabi, LMSServiceInterface
{
    use MapperTrait;
    private string $clientId;
    private string $clientSecret;
    private ?string $siteId;

    public UserService $users;
    public CourseService $courses;
    public ProductService $products;
    public OrderService $orders;
    public EnrollmentService $enrollments;
    public CustomerService $customers;
    public OfferService $offers;
    public SiteService $sites;
    public WebhookService $webhooks;
    public CourseProgressService $courseProgress;
    public CustomProfileFieldDefinitionService $customProfileFieldDefinitions;

    /**
     * Backward-compatible alias for $customProfileFieldDefinitions
     * (pre-2.0.0 name; consumers like the Thinkific B2B Dashboard access
     * this snake_case name, so it must not fatal on 2.x).
     *
     * @deprecated Use $customProfileFieldDefinitions instead.
     */
    public CustomProfileFieldDefinitionService $custom_profile_field_definitions;

    // Compatibility stub services (Kajabi doesn't support these features)
    public BundleService $bundles;
    public ChapterService $chapters;
    public ContentService $contents;
    public CouponService $coupons;
    public CourseReviewService $courseReviews;
    public GroupService $groups;
    public InstructorService $instructors;
    public PromotionService $promotions;
    public SiteScriptService $siteScripts;
    public OAuthService $oauth;
    private KajabiConnector|bool $connector = false;
    private Authenticator|bool $authenticator = false;

    public function __construct(string $clientId, string $clientSecret, ?string $siteId = null)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->siteId = $siteId;

        $this->boot();
    }

    public function boot(): void
    {
        // Core services with full Kajabi API support
        $this->users = new UserService($this);
        $this->courses = new CourseService($this);
        $this->products = new ProductService($this);
        $this->orders = new OrderService($this);
        $this->enrollments = new EnrollmentService($this);
        $this->customers = new CustomerService($this);
        $this->offers = new OfferService($this);
        $this->sites = new SiteService($this);
        $this->webhooks = new WebhookService($this);
        $this->courseProgress = new CourseProgressService($this);
        $this->customProfileFieldDefinitions = new CustomProfileFieldDefinitionService($this);
        $this->custom_profile_field_definitions = $this->customProfileFieldDefinitions;

        // Compatibility stub services (Thinkific features not available in Kajabi)
        $this->bundles = new BundleService($this);
        $this->chapters = new ChapterService($this);
        $this->contents = new ContentService($this);
        $this->coupons = new CouponService($this);
        $this->courseReviews = new CourseReviewService($this);

        $this->groups = new GroupService($this);
        $this->instructors = new InstructorService($this);
        $this->promotions = new PromotionService($this);
        $this->siteScripts = new SiteScriptService($this);
        $this->oauth = new OAuthService($this);
    }

    /**
     * @return KajabiConnector
     */
    public function connector(): KajabiConnector
    {
        if ($this->connector) {
            return $this->connector;
        }

        /**
         * Default Connector
         */
        $connector = new KajabiConnector();
        $authenticator = $this->authenticator();
        $authenticator->connector = $connector; // Set connector for authentication

        // Kept, so every service shares it and $kajabi->connector()->setRateLimit()
        // etc. configure the connector that actually sends
        return $this->connector = $connector->authenticate($authenticator);
    }

    /**
     * @return Authenticator
     */
    public function authenticator(): Authenticator
    {
        if ($this->authenticator) {
            return $this->authenticator;
        }

        $this->authenticator = new KajabiAuthenticator(
            $this->clientId,
            $this->clientSecret
        );
        
        return $this->authenticator;
    }

    /**
     * Dynamically set the Connector
     *
     * @param KajabiConnector|bool $connector
     * @return void
     */
    public function setConnector(KajabiConnector|bool $connector): void
    {
        $this->connector = $connector;
    }

    /**
     * Dynamically set the Authenticator
     *
     * @param Authenticator|bool $authenticator
     * @return void
     */
    public function setAuthenticator(Authenticator|bool $authenticator): void
    {
        $this->authenticator = $authenticator;
    }

    /**
     * Reset the Connector and Authenticator
     *
     * @return void
     */
    public function resetService(): void
    {
        $this->connector = false;
        $this->authenticator = false;
    }

    /**
     * Get the current access token
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        $authenticator = $this->authenticator();
        
        if ($authenticator instanceof KajabiAuthenticator) {
            return $authenticator->getAccessToken();
        }
        
        return null;
    }

    /**
     * The current token with its expiry and refresh token, or null before the first exchange
     */
    public function getToken(): ?AccessToken
    {
        $authenticator = $this->authenticator();

        return $authenticator instanceof KajabiAuthenticator ? $authenticator->getToken() : null;
    }

    /**
     * Unix time the current token expires (Kajabi's expiry, no safety margin), or null when unknown
     */
    public function getTokenExpiresAt(): ?int
    {
        return $this->getToken()?->expiresAt;
    }

    /**
     * Set the access token to bypass authentication
     *
     * @param string|AccessToken $token A cached AccessToken keeps its own expiry
     * @param int|null $expiresIn Seconds until expiry (from the token response); when set,
     *                            a new token is fetched shortly before it runs out
     * @return void
     */
    public function setAccessToken(string|AccessToken $token, ?int $expiresIn = null): void
    {
        $authenticator = $this->authenticator();
        
        if ($authenticator instanceof KajabiAuthenticator) {
            $authenticator->setAccessToken($token, $expiresIn);
        }
    }

    /**
     * Manually authenticate and retrieve access token
     * Useful for caching the token before making API requests
     *
     * @return string|null
     * @see fetchAccessToken() for the expiry and refresh token as well
     */
    public function authenticate(): ?string
    {
        return $this->authenticator() instanceof KajabiAuthenticator
            ? $this->fetchAccessToken()->token
            : null;
    }

    /**
     * Exchange the client credentials for a new token and return it with its expiry
     *
     * ```php
     * $token = $kajabi->fetchAccessToken();
     * Cache::put('kajabi_token', $token, $token->secondsRemaining());
     * // later
     * $kajabi->setAccessToken(Cache::get('kajabi_token'));
     * ```
     *
     * @throws \LogicException when a custom (non-Kajabi) authenticator is set
     */
    public function fetchAccessToken(): AccessToken
    {
        $authenticator = $this->authenticator();

        if (!$authenticator instanceof KajabiAuthenticator) {
            throw new \LogicException('fetchAccessToken() needs the KajabiAuthenticator');
        }

        $authenticator->connector = $this->connector();

        return $authenticator->refresh();
    }

    /**
     * Get the current site ID
     *
     * @return string|null
     */
    public function getSiteId(): ?string
    {
        return $this->siteId;
    }

    /**
     * Set the site ID for all requests
     *
     * @param string|null $siteId
     * @return void
     */
    public function setSiteId(?string $siteId): void
    {
        $this->siteId = $siteId;
    }

    // LMSServiceInterface implementation

    /**
     * Get the provider name
     *
     * @return string
     */
    public function getProviderName(): string
    {
        return 'kajabi';
    }

    /**
     * Check the credentials work and Kajabi answers: exchanges a token if needed,
     * then makes one read-only request (GET /v1/me)
     *
     * False on any failure - bad credentials, network, timeout, rate limit or a
     * Kajabi error. Costs a request (two without a cached token), so don't call it
     * before every API call.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            return $this->connector()->send(new GetMe())->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // Core service interfaces

    /**
     * Get the users service
     *
     * @return UserServiceInterface
     */
    public function users(): UserServiceInterface
    {
        return $this->users;
    }

    /**
     * Get the courses service
     *
     * @return CourseServiceInterface
     */
    public function courses(): CourseServiceInterface
    {
        return $this->courses;
    }

    /**
     * Get the enrollments service
     *
     * @return EnrollmentServiceInterface
     */
    public function enrollments(): EnrollmentServiceInterface
    {
        return $this->enrollments;
    }

    /**
     * Get the products service
     *
     * @return ProductServiceInterface
     */
    public function products(): ProductServiceInterface
    {
        return $this->products;
    }

    /**
     * Get the orders service
     *
     * @return OrderServiceInterface
     */
    public function orders(): OrderServiceInterface
    {
        return $this->orders;
    }
}
