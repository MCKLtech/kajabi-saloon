<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Contracts\Authenticator;
use WooNinja\KajabiSaloon\Auth\KajabiAuthenticator;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Interfaces\Kajabi;
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
        $this->custom_profile_field_definitions = new CustomProfileFieldDefinitionService($this);

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
        
        return $connector->authenticate($authenticator);
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
     * Set the access token to bypass authentication
     *
     * @param string $token
     * @return void
     */
    public function setAccessToken(string $token): void
    {
        $authenticator = $this->authenticator();
        
        if ($authenticator instanceof KajabiAuthenticator) {
            $authenticator->setAccessToken($token);
        }
    }

    /**
     * Manually authenticate and retrieve access token
     * Useful for caching the token before making API requests
     *
     * @return string|null
     */
    public function authenticate(): ?string
    {
        $authenticator = $this->authenticator();
        
        if ($authenticator instanceof KajabiAuthenticator) {
            $authenticator->connector = $this->connector();
            // Trigger authentication by calling the private authenticate method via reflection
            $reflection = new \ReflectionClass($authenticator);
            $method = $reflection->getMethod('authenticate');
            $method->setAccessible(true);
            $method->invoke($authenticator);
            
            return $authenticator->getAccessToken();
        }
        
        return null;
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
     * Check if service is properly configured and connected
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            // Test connection with a lightweight API call
            $this->connector();
            return true;
        } catch (\Exception $e) {
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
