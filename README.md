# Kajabi Saloon

A PHP library for the Kajabi API using Saloon. Drop-in replacement for `mckltech/thinkific-saloon` with identical interfaces and response structures.

## Features

- ✅ **OAuth2 Authentication** - Automatic token management with Client Credentials flow
- ✅ **Pagination Support** - Built-in pagination matching Thinkific's interface
- ✅ **Rate Limiting** - Configurable rate limiting (default: 100 req/min)
- ✅ **Type Safety** - Full DTOs implementing lms-contracts interfaces
- ✅ **Global Site ID** - Set once, use everywhere
- ✅ **Filter Translation** - Automatically translates Thinkific filters to Kajabi format

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

```php
// Customize rate limit (default: 100 requests/minute)
$connector = $kajabi->connector();
$connector->setRateLimit(200);

// Use custom rate limit store (e.g., Laravel Cache)
use Saloon\RateLimitPlugin\Stores\LaravelCacheStore;
use Illuminate\Support\Facades\Cache;

$connector->setRateStore(new LaravelCacheStore(Cache::store()));
```

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
    echo "Progress: {$enrollment->getPercentageCompleted()}%" . PHP_EOL;
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

### Customers

```php
// Get all customers
$customers = $kajabi->customers->customers();

// Search customers
$customers = $kajabi->customers->customers(['search' => 'john@example.com']);

// Get specific customer
$customer = $kajabi->customers->get(123);
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
        echo "🎯 Progress: {$enrollment->getPercentageCompleted()}%\n";
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
$totalResults = $users->getTotalAPIResults();  // Total items from API
$totalPages = $users->getTotalAPIPages();      // Total pages from API

// Collect all items into array
$allUsers = $users->collect();
```

## API Mapping

This library provides a **drop-in replacement** for Thinkific by mapping Kajabi endpoints:

| Thinkific Service | Kajabi Endpoint | Notes | Compatible Methods |
|------------------|-----------------|-------|---------------------|
| Users | `/contacts` | Direct mapping | `get()`, `users()`, `find()`, `findByEmail()`, `create()`, `update()`, `delete()` |
| Enrollments | `/contacts/{id}/relationships/offers` | Offer grants to Contacts | `get()`, `enrollments()`, `create()`, `update()`, `expire()`, `enrollmentsForUser()`, `enrollmentsForCourse()`, `enrollmentsForUserInCourse()`, `isUserEnrolledInCourse()` |
| Courses | `/products` | Products = Courses | `get()`, `courses()`, `product()`, `chapters()` |
| Products | `/products` | Direct mapping | `get()`, `products()`, `courses()`, `related()` |
| Orders | `/orders` | Purchase history | `get()`, `orders()` |
| Customers | `/customers` | Auto-created with offers | `get()`, `customers()` |
| Offers | `/offers` | Grant access to products | `get()`, `offers()` |
| Sites | `/sites` | Site information | `get()`, `sites()` |
| Webhooks | `/hooks` | Webhook management | `get()`, `webhooks()` |

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
- **Product relationship** → `course_id` extracted
- **Status mapping** → `active`, `completed`, `expired`, `cancelled`
- **Default progress** → `percentage_completed` = 0.0

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
| `query[course_id]` | `filter[product_id]` | Course filter |
| `limit` | `page[size]` | Page size |
| `page` | `page[number]` | Page number |
| `site_id` | `filter[site_id]` | Site filter |

**Use Thinkific filters - they work automatically!**

```php
// This works exactly like Thinkific:
$enrollments = $kajabi->enrollments->enrollments([
    'query[user_id]' => 123,
    'query[course_id]' => 456,
    'limit' => 50
]);
// Automatically translated to Kajabi format behind the scenes
```

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
- ✅ All 20 services present and functional
- ✅ Property access patterns match Thinkific
- ✅ DTO transformations (Kajabi → Thinkific format)
- ✅ Filter translation
- ✅ Pagination behavior

## API Reference

### API Version & Compliance

This library implements **Kajabi API V1** (OpenAPI 3.1.1 specification).

**Coverage**: 27/49 endpoints (55.1%)
- ✅ **Contacts (Users)**: 6/7 endpoints - Create, read, update, delete, list, manage offers
- ✅ **Courses**: 2/2 endpoints - Full course access
- ✅ **Customers**: 4/5 endpoints - Customer management
- ✅ **Enrollments (Purchases)**: 2/5 endpoints - List and view (subscription actions pending)
- ✅ **Offers**: 4/5 endpoints - Grant, revoke, list, view
- ✅ **Orders**: 2/2 endpoints - Order history
- ✅ **Products**: 2/2 endpoints - Product catalog
- ✅ **Sites**: 2/2 endpoints - Site information
- ✅ **Webhooks**: 2/2 endpoints - Webhook management (stubs)
- ✅ **Authentication**: 1/2 endpoints - OAuth2 token (revoke pending)

**Not Yet Implemented**: Blog posts, contact notes/tags, custom fields, forms, landing pages, order items, transactions, website pages

### Base URL

```
https://api.kajabi.com/v1
```

### Authentication

OAuth2 Client Credentials flow with automatic token management

## Requirements

- PHP 8.1 or higher
- Composer
- Kajabi API credentials (Client ID, Client Secret, Site ID)

## Dependencies

- `saloonphp/saloon` ^3.0 - HTTP client
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

- **[openapi.yaml](openapi.yaml)** - Official Kajabi API V1 OpenAPI 3.1.1 specification
