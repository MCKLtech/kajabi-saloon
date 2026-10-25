# Kajabi Saloon

A PHP library for the Kajabi API using Saloon. Drop-in replacement for `mckltech/thinkific-saloon` with identical interfaces and response structures.

## Features

- ✅ **OAuth2 Authentication** - Automatic token management with Client Credentials flow
- ✅ **Pagination Support** - Built-in pagination matching Thinkific's interface
- ✅ **Rate Limiting** - Client-side limit (default: 100 req/min; Kajabi blocks an IP for 10 minutes above ~1000/min), throws by default
- ✅ **Type Safety** - Full DTOs implementing lms-contracts interfaces
- ✅ **Global Site ID** - Set once, use everywhere
- ✅ **Filter Translation** - Automatically translates Thinkific filters to Kajabi format
- ✅ **Strict Filters** - Optional mode that refuses filters Kajabi would silently ignore
- ✅ **Course Progress & Member Activity** - Resumable progress sync, typed webhook events, course structure (modules/lessons)

Behaviour below marked *verified live* was checked against the Kajabi API on 2026-10-24, where it differs from or goes beyond `openapi.yaml`.

## Installation

```bash
composer require mckltech/kajabi-saloon
```

## Quick Start

```php
use WooNinja\KajabiSaloon\Services\KajabiService;

// Initialize with OAuth2 credentials and site ID
$kajabi = new KajabiService(
    'your-client-id',
    'your-client-secret',
    'your-site-id'  // Optional but recommended
);

// Use exactly like Thinkific
$users = $kajabi->users->users(['limit' => 50]);
foreach ($users->items() as $user) {
    echo $user->getFullName() . ' - ' . $user->email . PHP_EOL;
}
```

## Understanding Kajabi's Architecture

**Important**: Kajabi's architecture differs from Thinkific. This library attempts to handle the differences transparently, but understanding them helps:

### Key Concepts

1. **Products, Not Courses**
   - Kajabi has **Products** (courses, communities, coaching programs, memberships)
   - What Thinkific calls a "Course" is a "Product" in Kajabi
   - The library maps `courses` → `products` automatically

2. **Offers Grant Access**
   - An **Offer** grants access to one or multiple Products
   - Enrollments work by granting Offers to Contacts
   - When you "enroll a user in a course," you're actually "granting an Offer to a Contact"
   - One Offer can bundle multiple Products together

3. **Contacts vs Customers**
   - **Contacts** = All users in your Kajabi site (like Thinkific users)
   - **Customers** = Automatically created when a Contact is granted an Offer
   - The library works with Contact IDs (user_id) and handles Customer resolution internally

4. **Translation Layer**
   - `user_id` = Contact ID
   - `course_id` = Offer ID (not Product ID!)
   - `create()` = Grant Offer to Contact (enrollment)
   - `expire()` = Revoke Offer from Contact (unenrollment)


## Authentication

### Getting Your Credentials

1. Log into your **Kajabi Admin Portal**
2. Navigate to **Settings** → **User API Keys**
3. Create or view your API credentials:
   - **Client ID** - Your OAuth2 client identifier
   - **Client Secret** - Your OAuth2 client secret
   - **Site ID** - Your Kajabi site identifier (found in site settings)

### OAuth2 Client Credentials Flow

The library uses OAuth2 Client Credentials flow for authentication:

```php
// The library handles authentication automatically
$kajabi = new KajabiService('client-id', 'client-secret', 'site-id');

// First API call triggers authentication:
// 1. Requests access token from Kajabi OAuth endpoint
// 2. Caches token for subsequent requests
// 3. Automatically includes Bearer token in all requests

$users = $kajabi->users->users(); // Authentication happens here automatically
```

**No manual token management needed!** The library:
- Automatically obtains OAuth2 access tokens on first API call
- Caches tokens for the duration of the request
- Includes `Authorization: Bearer {token}` header automatically
- Throws exceptions if authentication fails

### Authentication Error Handling

```php
try {
    $kajabi = new KajabiService('invalid-id', 'invalid-secret', 'site-123');
    $users = $kajabi->users->users(); // Authentication attempted here
} catch (\Exception $e) {
    if (str_contains($e->getMessage(), 'Failed to authenticate')) {
        // Invalid credentials
        echo "Authentication failed: Check your client_id and client_secret";
    }
}
```

To check credentials up front (e.g. when a tenant saves their keys), `isConnected()` exchanges a token and makes one read-only request (`GET /v1/me`); it returns `false` on any failure rather than throwing:

```php
if (!$kajabi->isConnected()) {
    // bad credentials, Kajabi unreachable, or rate limited
}
```

### Access Token Caching

For improved performance, you can cache the access token to avoid repeated OAuth requests. Kajabi's tokens last **7 days** (`expires_in` 604800) and come with a refresh token.

```php
// Recommended: fetch the token with its expiry, cache it for exactly that long
$token = $kajabi->fetchAccessToken();         // WooNinja\KajabiSaloon\Auth\AccessToken
$token->token;          // the bearer token
$token->expiresAt;      // Unix time Kajabi's expiry runs out
$token->refreshToken;
Cache::put('kajabi_token', $token, $token->secondsRemaining());

// Later: restore it with its expiry; it's refreshed automatically 60s before it runs out
$kajabi->setAccessToken(Cache::get('kajabi_token'));
$kajabi->getTokenExpiresAt();
```

`authenticate()` still returns just the token string:

```php
// Method 1: Manually authenticate and cache the token
$kajabi = new KajabiService('client-id', 'client-secret', 'site-id');

// Trigger authentication and get the token
$accessToken = $kajabi->authenticate();

// Cache the token (using your preferred caching system)
Cache::put('kajabi_access_token', $accessToken, now()->addMinutes(60));

echo "Token cached: {$accessToken}";
```

```php
// Method 2: Restore a cached token to bypass authentication
$kajabi = new KajabiService('client-id', 'client-secret', 'site-id');

// Retrieve cached token
$cachedToken = Cache::get('kajabi_access_token');

if ($cachedToken) {
    // Set the token directly - no OAuth request needed!
    $kajabi->setAccessToken($cachedToken);
    echo "Using cached token";
} else {
    // No cached token, authenticate and cache it
    $accessToken = $kajabi->authenticate();
    Cache::put('kajabi_access_token', $accessToken, now()->addMinutes(60));
    echo "Authenticated and cached token";
}

// All subsequent API calls will use the token
$users = $kajabi->users->users();
```

```php
// Method 3: Complete caching workflow
function getKajabiService($clientId, $clientSecret, $siteId)
{
    $kajabi = new KajabiService($clientId, $clientSecret, $siteId);
    
    // Check if we have a cached token
    $cachedToken = Cache::get('kajabi_access_token');
    
    if ($cachedToken) {
        // Use cached token
        $kajabi->setAccessToken($cachedToken);
    } else {
        // Authenticate and cache the token
        $token = $kajabi->authenticate();
        
        if ($token) {
            // Cache for ~60 minutes (tokens typically expire in 7 days)
            Cache::put('kajabi_access_token', $token, now()->addMinutes(60));
        }
    }
    
    return $kajabi;
}

// Usage
$kajabi = getKajabiService('client-id', 'client-secret', 'site-id');
$users = $kajabi->users->users();
```

```php
// Method 4: Retrieve token after first API call
$kajabi = new KajabiService('client-id', 'client-secret', 'site-id');

// Make any API call (authentication happens automatically)
$users = $kajabi->users->users();

// Now retrieve and cache the token for future use
$accessToken = $kajabi->getAccessToken();
if ($accessToken) {
    Cache::put('kajabi_access_token', $accessToken, now()->addMinutes(60));
}
```

**Benefits of Token Caching:**
- ⚡ Faster API requests (skip OAuth flow)
- 🔒 Reduced authentication requests
- 📊 Better rate limit utilization

**Important Notes:**
- Cache tokens securely (never in version control)
- Consider shorter cache TTL (e.g., 1 hour) for security
- Use `setAccessToken()` to restore cached tokens
- Use `getAccessToken()` to retrieve current token for caching
- Use `authenticate()` to manually trigger OAuth and get a fresh token

## Configuration

### Global Site ID

Kajabi requires a `site_id` for most API calls. Set it globally to avoid repetition:

```php
// Method 1: Set during construction (recommended)
$kajabi = new KajabiService('client-id', 'client-secret', 'site-123');

// Method 2: Set after construction
$kajabi = new KajabiService('client-id', 'client-secret');
$kajabi->setSiteId('site-123');

// Get current site ID
$siteId = $kajabi->getSiteId();

// Override per request
$users = $kajabi->users->users(['site_id' => 'different-site-id']);
```

### Rate Limiting

Kajabi doesn't publish its limit. Measured on 2026-10-24, it is **about 1000 requests per sliding 60 seconds per client IP**, enforced by Cloudflare for every token and site from that IP. Going over returns `429` (`text/plain` body `error code: 1015`, `Retry-After: 600`) and **blocks the IP for 10 minutes** — every endpoint, including the token exchange.

The SDK keeps a client-side limit (default **100/min**, a deliberately safe tenth of that) and, by default, **throws** `RateLimitReachedException` when it is reached. After a real 429 it also stops sending until `Retry-After` has passed, because requests during a block risk extending it.

```php
use Saloon\RateLimitPlugin\Exceptions\RateLimitReachedException;

$connector = $kajabi->connector();

// Share one bucket across every process on the server (recommended for queue workers)
use Saloon\RateLimitPlugin\Stores\LaravelCacheStore;
use Illuminate\Support\Facades\Cache;
$connector->setRateStore(new LaravelCacheStore(Cache::store()));

// With a shared store you can raise the client-side limit, staying under the IP limit
$connector->setRateLimit(900);

// Handle the limit by rescheduling, not waiting (e.g. in a Laravel job)
try {
    $kajabi->courseProgress->page($productId, [], $cursor);
} catch (RateLimitReachedException $e) {
    $this->release($connector->secondsUntilAvailable());
}
```

**Don't sleep on rate limits.** `setSleepOnRateLimit()` exists for one-off scripts, but it is off by default and not recommended: sleeping holds a worker doing nothing, can outlast job and HTTP timeouts, and hides back-pressure from the caller. Throwing and rescheduling is standard practice. Even with sleep enabled, waits longer than `maxRateLimitSleepSeconds` (60s) — such as a 10-minute block after a 429 — still throw.

Every connector shares the bucket `kajabi-api` by default, because the limit is per IP rather than per site or token. Use `setRateLimitName()` only for sites that really send from different IPs.

### Timeouts

Requests time out after **10s to connect and 60s overall** (a stalled response otherwise holds a worker indefinitely). A timeout throws `Saloon\Exceptions\Request\FatalRequestException`.

```php
$kajabi->connector()->setTimeouts(connectSeconds: 10, requestSeconds: 120);
```

Send a normal `User-Agent` (the SDK's default is fine): Cloudflare answers `403` (`error code: 1010`) to some library defaults such as `Python-urllib`.

## Core Usage

### Users (Kajabi Contacts)

```php
// Get all users with pagination
$users = $kajabi->users->users(['limit' => 50]);

// Iterate through results
foreach ($users->items() as $user) {
    echo "{$user->getFullName()} ({$user->email})" . PHP_EOL;
}

// Get pagination info (after making at least one API call)
$totalResults = $users->getTotalAPIResults(); // meta.total_count
$totalPages = $users->getTotalAPIPages();     // meta.total_pages

// Get specific user by ID
$user = $kajabi->users->get(123);

// Find user by email or ID
$user = $kajabi->users->find('user@example.com');
$user = $kajabi->users->find(123);

// Find by email only
$user = $kajabi->users->findByEmail('user@example.com');

// Create new user
$newUser = $kajabi->users->create([
    'email' => 'newuser@example.com',
    'name' => 'John Doe',  // Split into first_name/last_name automatically
    'phone_number' => '+1234567890',
    'address_line_1' => '123 Main St',
    'address_city' => 'New York',
    'address_state' => 'NY',
    'address_zip' => '10001',
    'address_country' => 'USA'
]);

// Update user (requires UpdateContact request)
$updatedUser = $kajabi->users->update(123, [
    'name' => 'Jane Doe',
    'phone_number' => '+0987654321'
]);

// Delete user
$kajabi->users->delete(123);
```

### Enrollments (Kajabi Offer Grants)

**100% Thinkific Compatible** - Uses the exact same methods and filters!

**IMPORTANT**: In Kajabi, enrollments are managed by granting **Offers** to **Contacts** (users). An Offer can grant access to one or multiple Products.

```php
// Get all enrollments (purchases)
$enrollments = $kajabi->enrollments->enrollments();

// Get enrollments for specific user (by Contact ID or email)
$enrollments = $kajabi->enrollments->enrollmentsForUser(123);
$enrollments = $kajabi->enrollments->enrollmentsForUser('user@example.com');

// Get enrollments for specific offer
$enrollments = $kajabi->enrollments->enrollmentsForCourse(456);  // 456 = Offer ID

// Get enrollments for user in specific offer
$enrollments = $kajabi->enrollments->enrollmentsForUserInCourse(123, 456);

// Check if user is enrolled
$isEnrolled = $kajabi->enrollments->isUserEnrolledInCourse(123, 456);
$isEnrolled = $kajabi->enrollments->isUserEnrolledInCourse('user@example.com', 456);

// Create enrollment (grants Offer to Contact)
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\CreateEnrollment;

$enrollment = $kajabi->enrollments->create(
    new CreateEnrollment(
        user_id: 123,        // Contact ID
        course_id: 456,      // Offer ID
        activated_at: now()
    )
);

// Expire enrollment (revokes Offer from Contact)
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\DeleteEnrollment;

$response = $kajabi->enrollments->expire(
    new DeleteEnrollment(
        user_id: 123,    // Contact ID
        course_id: 456   // Offer ID
    )
);

// Get specific enrollment (requires both user_id and course_id)
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\ReadEnrollment;

$enrollment = $kajabi->enrollments->get(
    new ReadEnrollment(
        user_id: 123,    // Contact ID
        course_id: 456   // Offer ID
    )
);

// Access enrollment data (Thinkific-compatible interface)
foreach ($enrollments->items() as $enrollment) {
    echo "User: {$enrollment->getUserEmail()}" . PHP_EOL;
    echo "Course: {$enrollment->getCourseName()}" . PHP_EOL;
    echo "Progress: " . round($enrollment->percentage_completed * 100) . "%" . PHP_EOL;
    echo "Completed: " . ($enrollment->isCompleted() ? 'Yes' : 'No') . PHP_EOL;
    echo "Started: {$enrollment->getStartedAt()->format('Y-m-d')}" . PHP_EOL;
}

// Use Thinkific-style filters (automatically translated to Kajabi)
$enrollments = $kajabi->enrollments->enrollments([
    'query[user_id]' => 123,
    'query[course_id]' => 456,
    'query[email]' => 'user@example.com',
    'limit' => 50
]);
```

### Courses

```php
// Get all courses
$courses = $kajabi->courses->courses(['limit' => 20]);

// Get specific course
$course = $kajabi->courses->get(123);

// Access course data
foreach ($courses->items() as $course) {
    echo "Course: {$course->getName()}" . PHP_EOL;
    echo "Slug: {$course->getSlug()}" . PHP_EOL;
    echo "Product ID: {$course->getProductId()}" . PHP_EOL;
}
```

`$kajabi->courses` lists **offers** (what members are enrolled in), for Thinkific compatibility. `$course->product_ids` lists the products an offer grants; `chapter_ids` is kept for the contract but holds product ids here.

#### Modules and lessons

Kajabi has no lesson or module endpoints; both come from one `GET /v1/courses/{id}?include=modules,lessons` request. Pass a **product id** — a course product's id is also its course id (*verified live*).

```php
$structure = $kajabi->courses->structure($productId);  // CourseStructure

foreach ($structure->modules as $module) {               // ordered by position
    echo $module->title . PHP_EOL;
    foreach ($structure->lessonsInModule($module->id) as $lesson) {
        echo "  {$lesson->position}. {$lesson->title} ({$lesson->publishing_option})" . PHP_EOL;
    }
}

$structure->lessonTitles();   // lesson_id => title, e.g. to label course progress rows
$kajabi->courses->lessons($productId);   // Lesson[] ordered by module, then position
$kajabi->courses->modules($productId);   // Module[]
```

Positions are integers starting at 0. Products that aren't courses (communities, coaching) answer 404; an id not on the account answers 403.

### Products

```php
// Get all products
$products = $kajabi->products->products();

// Get specific product
$product = $kajabi->products->get(456);

// Access product data
foreach ($products->items() as $product) {
    echo "Product: {$product->getName()}" . PHP_EOL;
    echo "Price: ${$product->getPrice()}" . PHP_EOL;
    echo "Slug: {$product->getSlug()}" . PHP_EOL;
}
```

Like `courses`, `products()` returns **offers** for Thinkific compatibility. For Kajabi's own products (`/v1/products`):

```php
$kajabi->products->kajabiProducts(['name_cont' => 'Mastery']);   // Paginator of Product
$kajabi->products->kajabiProduct($productId);

// Only "Course" products have modules, lessons and course progress
foreach ($kajabi->products->courseProducts() as $product) {
    echo "{$product->name}: {$product->members_aggregate_count} members" . PHP_EOL;
}
$product->product_type_name;   // Course, AccessGroup, CoachingProgram, ...
$product->isCourse();
```

`filter[status_eq]` is in the spec but ignored by the API (*verified live*), so filter on status client-side. `exclude_product_types` takes Kajabi's internal type names (e.g. `GetProducts::COHORT_COURSE`).

### Orders

```php
// Get all orders
$orders = $kajabi->orders->orders();

// Get orders for specific customer
$orders = $kajabi->orders->orders(['user_id' => 123]);

// Get specific order
$order = $kajabi->orders->get(789);

// Access order data
foreach ($orders->items() as $order) {
    echo "Order ID: {$order->getId()}" . PHP_EOL;
    echo "User: {$order->getUserEmail()}" . PHP_EOL;
    echo "Amount: ${$order->getAmountDollars()}" . PHP_EOL;
    echo "Date: {$order->getCreatedAt()->format('Y-m-d')}" . PHP_EOL;
}
```

Orders are requested with `include=order_items,customer`: `items` holds each line (offer id, title, amount, purchase id), `product_id`/`product_name` are the first item's offer, and amounts come from Kajabi's cents fields (`amount_cents`, `subtotal_cents`, `sales_tax_cents`, `discount_cents`, `currency`, `order_number`). An order whose customer has since been deleted has an empty `user_email`.

### Customers

```php
// Get all customers
$customers = $kajabi->customers->customers();

// Search customers
$customers = $kajabi->customers->customers(['search' => 'john@example.com']);

// Get specific customer
$customer = $kajabi->customers->get(123);
$customer->contact_id;
```

Course progress and some webhooks identify members by **customer** id; contacts (users) are keyed by **contact** id. `/customers` has no id filter (`filter[id]` and `filter[contact_id]` are silently ignored), so map ids in bulk from the list — a few requests for thousands of members:

```php
$kajabi->customers->contactIds([$customerId1, $customerId2]);  // [customer_id => contact_id], stops once all are found
$kajabi->customers->contactIdMap();                            // every customer on the site
$kajabi->users->customerIds([$contactId]);                     // [contact_id => customer_id]; contacts without a customer are left out
```

### Other Services

```php
// Offers
$offers = $kajabi->offers->offers();
$offer = $kajabi->offers->get(456);

// Sites
$sites = $kajabi->sites->sites();
$site = $kajabi->sites->get(123);

// Webhooks
$webhooks = $kajabi->webhooks->webhooks();
$webhook = $kajabi->webhooks->get(456);
```

### Webhooks & Member Activity

```php
use WooNinja\KajabiSaloon\Enums\WebhookEvent;

// One hook per event per URL (https only). Course events can be scoped to one course.
$hook = $kajabi->webhooks->create(WebhookEvent::LessonCompleted, 'https://example.com/hooks/k7f3', $courseId);
$hook = $kajabi->webhooks->replace($hook, 'https://example.com/hooks/new-path'); // create new, then delete old
$kajabi->webhooks->delete($hook->id);

// Sample payloads, in the same shape as a live delivery
$payload = $kajabi->webhooks->sample(WebhookEvent::CourseCompleted);
```

Parse inbound deliveries into typed events (framework agnostic):

```php
use WooNinja\KajabiSaloon\Webhooks\WebhookParser;
use WooNinja\KajabiSaloon\Webhooks\Events\{LessonCompleted, CourseStarted, CourseCompleted, AssessmentCompleted, MemberSignedIn};

$event = WebhookParser::parse($request->getContent()); // or the decoded array

// Delivery is at least once: dedupe retries on the delivery id, and the same
// activity sent under different ids (e.g. overlapping hooks) on the content key.
if ($store->seen($event->deliveryId()) || $store->seen($event->contentKey())) {
    return response('', 200);
}

match (true) {
    $event instanceof LessonCompleted => markLesson($event->contactId(), $event->courseId(), $event->lessonId(), $event->occurredAt()),
    $event instanceof CourseCompleted => markCourseComplete($event->contactId(), $event->courseId(), $event->occurredAt()),
    $event instanceof CourseStarted => markCourseStarted($event->contactId(), $event->courseId(), $event->occurredAt()),
    $event instanceof AssessmentCompleted => recordResult($event->contactId(), $event->assessment, $event->result), // passed/grade null when ungraded
    $event instanceof MemberSignedIn => touchLastSeen($event->customerId(), $event->occurredAt()), // best-effort signal
    default => null, // GenericWebhookEvent for purchase, tag_added, etc.
};
```

Return a 2xx quickly and process in the background. Only a 429 response is retried, so run the course progress sync below to catch anything missed. Deliveries are unsigned, so use a hard-to-guess URL.

### Course Progress

```php
// Every member's progress in a course (course id = product id), per lesson
foreach ($kajabi->courseProgress->forCourse($courseId)->items() as $row) {
    echo "{$row->customer_id}: {$row->percent_complete}% ({$row->completed_lessons}/{$row->total_lessons})";
}

$row = $kajabi->courseProgress->forCustomer($courseId, $customerId); // null if no progress record

// One page at a time (pages hold at most 100 rows; the cursor survives between processes)
$page = $kajabi->courseProgress->page($courseId, ['limit' => 100], $cursor); // $cursor null = first page
foreach ($page->rows as $row) { /* ... */ }
$cursor = $page->nextCursor; // null on the last page

// Recommended sync: frequent incremental runs plus a nightly full pass
$sync = $kajabi->courseProgress->sync(); // 5 minute margin by default
$result = $sync->incremental($courseId, $lastSyncStartedAt, fn($row) => $repo->upsert($row));
$lastSyncStartedAt = $result->started_at; // persist only after the run succeeds

$result = $sync->full($courseId, fn($row) => $repo->upsert($row));
$repo->delete($result->missingFrom($repo->idsForCourse($courseId)));

// With a time budget: park the run and resume it in a later job
$deadline = time() + 50;
$result = $checkpoint
    ? $sync->resume($checkpoint, $upsert, fn() => time() >= $deadline)
    : $sync->incremental($courseId, $lastSyncStartedAt, $upsert, fn() => time() >= $deadline);

if ($result->isComplete()) {
    $lastSyncStartedAt = $result->started_at;          // the original run's start, even after resumes
} else {
    $store->put('checkpoint', $result->checkpoint()->toArray());  // later: SyncCheckpoint::fromArray(...)
}

// Real progress on an enrollment (opt-in; extra requests per call)
$enrollment = $kajabi->enrollments->withProgress($enrollment);
echo $enrollment->percentage_completed; // 0.0 - 1.0
```

Errors (all extend `RequestException`, in `WooNinja\KajabiSaloon\Exceptions`):

| Status | Exception | Meaning |
|---|---|---|
| 423 | `FeatureNotEnabledException` | Course progress isn't available on this account |
| 403 | `CourseProgressForbiddenException` | The API key lacks course progress access |
| 404 | `CourseProgressNotFoundException` | Not a "Course" product (community, coaching, podcast, cohort) or not on the account — permanent for that id, stop polling it |

`missingFrom()` refuses a full pass that was split across resumed runs (its row ids are partial); stamp rows with `started_at` in the upsert and delete the course's rows stamped earlier instead.

`percentage_completed` on enrollments from offers or purchases is a placeholder: check `$enrollment->progress_known`, which is `true` only once `withProgress()` has applied real course progress. Offer enrollments also leave `started_at`/`activated_at`/`created_at` null unless the grant time is known (`enrollments->create()` records it).

## Complete Workflow Examples

### Create User and Enroll in Course

```php
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\CreateEnrollment;

try {
    // Step 1: Create or find user
    $user = $kajabi->users->findByEmail('student@example.com');

    if (!$user) {
        $user = $kajabi->users->create([
            'email' => 'student@example.com',
            'name' => 'Jane Student'
        ]);
        echo "✅ User created: {$user->id}\n";
    } else {
        echo "✅ User found: {$user->id}\n";
    }

    // Step 2: Enroll in course (offer ID 456)
    $offerId = 456;
    if ($kajabi->enrollments->isUserEnrolledInCourse($user->id, $offerId)) {
        echo "ℹ️  Already enrolled\n";
    } else {
        $enrollment = $kajabi->enrollments->create(
            new CreateEnrollment(
                user_id: $user->id,
                course_id: $offerId,
                activated_at: now()
            )
        );
        echo "✅ Enrolled successfully\n";
    }

    // Step 3: Verify enrollment
    $enrollments = $kajabi->enrollments->enrollmentsForUserInCourse($user->id, $offerId);
    foreach ($enrollments->items() as $enrollment) {
        echo "📚 Enrollment: {$enrollment->getUserEmail()}\n";
        echo "🎯 Progress: " . round($enrollment->percentage_completed * 100) . "%\n";
    }

} catch (\Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
}
```

### Batch Enroll Users

```php
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\CreateEnrollment;

function batchEnrollUsers($kajabi, array $emails, int $offerId): array
{
    $results = [];

    foreach ($emails as $email) {
        try {
            // Find or create user
            $user = $kajabi->users->findByEmail($email) ??
                   $kajabi->users->create(['email' => $email, 'name' => $email]);

            // Create enrollment (grant offer)
            $enrollment = $kajabi->enrollments->create(
                new CreateEnrollment(
                    user_id: $user->id,
                    course_id: $offerId,
                    activated_at: now()
                )
            );

            $results[] = [
                'email' => $email,
                'user_id' => $user->id,
                'enrolled' => true
            ];

            echo "✅ {$email}\n";

        } catch (\Exception $e) {
            echo "❌ Error with {$email}: {$e->getMessage()}\n";
            $results[] = ['email' => $email, 'error' => $e->getMessage()];
        }
    }

    return $results;
}

// Usage - enroll multiple users in Offer ID 456
$results = batchEnrollUsers($kajabi, [
    'student1@example.com',
    'student2@example.com',
    'student3@example.com'
], 456);

$successful = array_filter($results, fn($r) => $r['enrolled'] ?? false);
echo "\n📊 Enrolled " . count($successful) . "/" . count($results) . " users\n";
```

## Pagination

All "list" methods return paginated results compatible with Thinkific's interface:

```php
$users = $kajabi->users->users([
    'limit' => 50,          // Page size (maps to page[size])
    'page' => 2,            // Page number (maps to page[number])
    'start_page' => 1,      // Starting page (default: 1)
    'max_pages' => 5        // Maximum pages to fetch
]);

// Iterate through all results
foreach ($users->items() as $user) {
    // Process user
}

// Get pagination metadata (after making at least one API call)
$totalResults = $users->getTotalAPIResults();  // meta.total
$totalPages = $users->getTotalAPIPages();      // from meta.total, else links.last

// Collect all items into array
$allUsers = $users->collect();
```

Only `/contacts` and `/customers` send a total (`meta.total`); other lists send none, so `getTotalAPIResults()` is 0 there — check `hasTotalAPIResults()`. `getTotalAPIPages()` falls back to the last page link. Most lists accept large pages (`page[size]=1000` works on `/customers`); `/course_progress` caps at 100.

## API Mapping

This library provides a **drop-in replacement** for Thinkific by mapping Kajabi endpoints:

| Thinkific Service | Kajabi Endpoint | Notes | Compatible Methods |
|------------------|-----------------|-------|---------------------|
| Users | `/contacts` | Direct mapping | `get()`, `users()`, `find()`, `findByEmail()`, `create()`, `update()`, `delete()`, `customerIds()` |
| Enrollments | `/contacts/{id}/relationships/offers` | Offer grants to Contacts | `get()`, `enrollments()`, `create()`, `update()`, `expire()`, `enrollmentsForUser()`, `enrollmentsForCourse()`, `enrollmentsForUserInCourse()`, `isUserEnrolledInCourse()` |
| Courses | `/offers`, `/courses/{id}` | Offers = Courses; structure from the course | `get()`, `courses()`, `product()`, `structure()`, `lessons()`, `modules()` |
| Products | `/offers`, `/products` | Offers = Products; Kajabi products separately | `get()`, `products()`, `courses()`, `kajabiProducts()`, `kajabiProduct()`, `courseProducts()` |
| Orders | `/orders` | Purchase history | `get()`, `orders()` |
| Customers | `/customers` | Auto-created with offers | `get()`, `customers()`, `contactIds()`, `contactIdMap()` |
| Offers | `/offers` | Grant access to products | `get()`, `offers()` |
| Sites | `/sites` | Site information | `get()`, `sites()` |
| Webhooks | `/hooks` | Webhook management | `get()`, `webhooks()`, `create()`, `delete()`, `replace()`, `sample()`, `parse()` |
| Course Progress | `/course_progress` | Per-member course progress | `forCourse()`, `forCustomer()`, `updatedSince()`, `page()`, `sync()` |

## Data Transformation

The library automatically transforms Kajabi's JSON:API format to match Thinkific:

### User (Contact) Transformation
- **Single `name` field** → Split into `first_name` and `last_name`
- **Custom fields** → `custom_profile_fields` array
- **Default role** → `['student']` (Kajabi has no roles)
- **JSON:API relationships** → Extracted to IDs

### Enrollment (Purchase) Transformation
- **Kajabi Purchase** → Thinkific Enrollment DTO
- **Customer relationship** → `user_id` extracted
- **Offer relationship** → `course_id` (the offer, matching the Thinkific "course")
- **Status** → from `deactivated_at`: `expired` only once it has passed (a future date is a scheduled end, still `active`); `expiry_date` = `deactivated_at`
- **Default progress** → `percentage_completed` = 0.0 with `progress_known` false (use `$kajabi->enrollments->withProgress()` for real progress)

### Course Transformation
- **Product relationship** → `product_id` extracted
- **Instructor relationships** → `instructor_id` and `administrator_user_ids`
- **Module relationships** → `chapter_ids`

## Filter Translation

Thinkific-style filters are automatically translated to Kajabi format:

| Thinkific Filter | Kajabi Filter | Example |
|------------------|---------------|---------|
| `query[email]` | `filter[search]` | Email search |
| `query[user_id]` | `filter[customer_id]` | User filter |
| `query[course_id]` | — | Dropped for purchases: Kajabi has no such filter |
| `limit` | `page[size]` | Page size |
| `page` | `page[number]` | Page number |
| `site_id` | `filter[site_id]` | Site filter |

**Use Thinkific filters - they work automatically!**

```php
// This works exactly like Thinkific:
$enrollments = $kajabi->enrollments->enrollments([
    'query[user_id]' => 123,
    'limit' => 50
]);
// Automatically translated to Kajabi format behind the scenes
```

### Unsupported filters are silently ignored

Kajabi answers a filter it doesn't support — a typo, `filter[id]`, `filter[contact_id]` on `/customers`, even `filter[status_eq]` on `/products` which the spec documents — with the **unfiltered** list and a 200 (*verified live*). Code relying on the filter then processes every record.

Strict mode makes such requests throw before they're sent. Turn it on in development and tests:

```php
$kajabi->connector()->setStrictFilters();

$kajabi->customers->customers(['filter[id]' => '1,2']);
// UnsupportedFilterException: WooNinja\KajabiSaloon\Requests\Customers\GetCustomers: Kajabi ignores id,
//   so the request would return unfiltered results. ...
```

Each list request declares what Kajabi honours (`GetCustomers::supportedFilters()`, from `openapi.yaml` minus filters found ignored live). Requests sent directly through the connector also have JSON:API helpers:

```php
use WooNinja\KajabiSaloon\Requests\Customers\GetCustomers;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;

$kajabi->connector()->send(
    (new GetCustomers())->filter('created_in_last', 7)->fields('customers', 'name', 'contact')->sort('-created_at')->pageSize(500)
);
$kajabi->connector()->send((new GetContact($id))->include('customer', 'tags'));
```

Sparse `fields[...]` also drops relationships that aren't named, so list a relationship (e.g. `contact`) to keep it.

## Error Handling

```php
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

try {
    $user = $kajabi->users->get(123);
} catch (FatalRequestException $e) {
    // 5xx server errors
    echo "Server error: " . $e->getMessage();
} catch (RequestException $e) {
    // 4xx client errors (not found, unauthorized, etc.)
    echo "Request error: " . $e->getMessage();
    $statusCode = $e->getResponse()->status();
    $body = $e->getResponse()->body();
} catch (\Exception $e) {
    // Authentication or other errors
    echo "Error: " . $e->getMessage();
}
```

## Unit Testing

The package includes a comprehensive test suite ensuring 100% Thinkific compatibility:

```bash
# Run all tests
vendor/bin/phpunit

# Run compatibility tests only
vendor/bin/phpunit --testsuite=Compatibility

# Run with coverage
vendor/bin/phpunit --coverage-html coverage
```

**Test Coverage:**
- ✅ All lms-contracts interfaces implemented
- ✅ All 21 services present and functional
- ✅ Property access patterns match Thinkific
- ✅ DTO transformations (Kajabi → Thinkific format)
- ✅ Filter translation
- ✅ Pagination behavior

## API Reference

### API Version & Compliance

This library implements **Kajabi API V1** (OpenAPI 3.1.1 specification, spec v1.1.0 — 60 paths / 77 operations).

**Coverage**: 28 of 60 spec paths (46.7%), 35 of 77 operations. The per-category counts below reflect the originally targeted endpoint subset; see [API_COMPLIANCE.md](API_COMPLIANCE.md) for the authoritative, spec-anchored coverage matrix.
- ✅ **Contacts (Users)**: 6/7 endpoints - Create, read, update, delete, list, manage offers
- ✅ **Courses**: 2/2 endpoints - Full course access
- ✅ **Customers**: 4/5 endpoints - Customer management
- ✅ **Enrollments (Purchases)**: 2/5 endpoints - List and view (subscription actions pending)
- ✅ **Offers**: 4/5 endpoints - Grant, revoke, list, view
- ✅ **Orders**: 2/2 endpoints - Order history
- ✅ **Products**: 2/2 endpoints - Product catalog
- ✅ **Sites**: 2/2 endpoints - Site information
- ✅ **Webhooks**: 10/10 spec operations - List, view, create, delete, samples, plus typed parsing of inbound deliveries
- ✅ **Course Progress**: `GET /v1/course_progress` - per-member progress with cursor paging and a sync helper
- ✅ **Authentication**: 1/2 endpoints - OAuth2 token (revoke pending)

**Not Yet Implemented**: Blog posts, contact notes/tags, custom fields, forms, landing pages, order items, transactions, website pages

### Base URL

```
https://api.kajabi.com/v1
```

### Authentication

OAuth2 Client Credentials flow with automatic token management

## Requirements

- PHP 8.2 or higher
- Composer
- Kajabi API credentials (Client ID, Client Secret, Site ID)

## Dependencies

- `saloonphp/saloon` ^4.0 - HTTP client
- `saloonphp/pagination-plugin` ^2.2 - Pagination support
- `saloonphp/rate-limit-plugin` ^2.0 - Rate limiting
- `nesbot/carbon` - Date/time handling
- `mckltech/lms-contracts` - Interface contracts for LMS compatibility

## License

MIT License

## Support

For issues, questions, or contributions:
- GitHub Issues: https://github.com/MCKLtech/kajabi-saloon/issues
- OpenAPI Spec: See [openapi.yaml](openapi.yaml) for official Kajabi API specification

## Related Packages

- [thinkific-saloon](https://github.com/MCKLtech/thinkific-saloon) - Original Thinkific implementation
- [lms-contracts](https://github.com/MCKLtech/lms-contracts) - Shared LMS interface contracts

## Documentation

- **[openapi.yaml](openapi.yaml)** - Official Kajabi API V1 OpenAPI 3.1.1 specification (spec v1.1.0, 60 paths / 77 operations; pinned SHA-256 and refresh procedure in [API_COMPLIANCE.md](API_COMPLIANCE.md#keeping-the-spec-in-sync))
