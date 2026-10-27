# Changelog

## 3.0.0

An offer is a bundle, a Kajabi course product is a course, and a contact who holds an offer is enrolled in each course product it grants.

### Breaking

- `Enrollment::$id` is `"{productId}:{contactId}"` (`Enrollment::getEnrollmentId(int $productId, int $contactId)`), not the offer and contact ids run together. Two offers that grant the same product give one enrollment. `Enrollment::parseEnrollmentId()` reverses it.
- `Enrollment::$course_id` is the **product** id, not the offer id. `Enrollment::fromKajabiOffer()` is replaced by `fromKajabiOfferProduct($offer, $product, $contactId, $email, $name, ?$contact, ?$grantedAt)`.
- `EnrollmentService` read methods take a **product** id: `get()` (or a `"{product}:{contact}"` `enrollment_id`), `enrollmentsForCourse()` (now `GET /contacts?filter[has_product_id]=`), `enrollmentsForUserInCourse()` and `isUserEnrolledInCourse()`. `enrollmentsForUser()` returns one enrollment per course product across the contact's offers. Write methods (`create`, `update`, `expire`, `enroll`, `unenroll`) still take an **offer** id.
- `EnrollmentService::create()` returns the enrollment in the offer's **first** course product. The new `createMany()` returns all of them. If the offer grants no course products, `create()` throws `UnexpectedValueException` after the grant has been made.
- `ProductService::courses($offerId)` returns one `Course` per course product of the offer (`id` and `product_id` are the product id, `name` is the product title), not the offer as a single course.
- `CourseService::courses()` lists the site's course products, not offers. `CourseService::get()` and `product()` take a product id.
- `Offer::fromKajabiOffer()` (the offers that `ProductService` returns) sets `productable_type` to `'Bundle'`, not `'course'`.
- Removed `Enrollment::withCourseProgress()` and `EnrollmentService::withProgress()`, which averaged progress across a whole offer. Read progress per product from `$kajabi->courseProgress`. `EnrollmentService::progress()` is still available.
- `GetContactOffers` and `GetContactsWithOffer` have new constructors (they now take the course products to expand into).
- New dependency: `psr/simple-cache` `^1.0|^2.0|^3.0`.

### Added

- `BundleService` is implemented (it used to throw): `get()`, `courses()`, `enrollments()`, `enrollmentsForUser()`, `createEnrollment()`, `updateEnrollment()` (makes no write when the offer is already held, grants it when not), `expireEnrollment()`, `isUserEnrolled()`, `hasActiveEnrollment()`, `hasAnyEnrollment()`. A new `Bundle` DTO is added.
- `ProductService::courseProductIdsForOffer()`, `courseProductsForOffer()` and `courseProductResources()` work out which course products an offer grants. Cohort courses count as courses; communities, coaching, podcasts and access groups do not.
- Expiry is idempotent: `EnrollmentService::expire()`, `unenroll()`, `update()` (past `expiry_date`) and `BundleService::expireEnrollment()` used to fail with Kajabi's 400 "Cannot remove relationships" when the contact didn't hold the offer. They now re-read the contact's offers and, if the offer is confirmed not held, return that successful response. Any other error, or that 400 while the offer is still held, is still thrown.
- `KajabiService::setCache(CacheInterface $cache, int $ttlSeconds = 86400)` caches the offer → products and course-product lookups under the keys `kajabi:{site}:offer_products:{offerId}` and `kajabi:{site}:course_products`. When no cache is set, each process uses its own `ArrayCache`.

### Deprecated

- `Course::fromKajabiOffer()`. It will be removed in 4.0.
