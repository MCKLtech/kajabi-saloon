# Live-shape fixtures (redacted)

Captured from the live Kajabi API on 2026-10-27 while checking the offer-as-bundle
model (read paths on the production site, write paths on a dedicated test contact).
Structure, attribute sets, status codes and content types are as Kajabi returned
them; every id, title, email and URL is replaced with a synthetic value.

- `products.json`: the product types seen live: `Course`, `AccessGroup`, `CoachingProgram`
  (the site had no cohort courses, so none here)
- `offer-*.json`: `GET /v1/offers/{id}`; live offers have no `created_at`/`updated_at`
- `contact-*.json`: `GET /v1/contacts/{id}?include=offers`; included offers carry `relationships.products`
- `grant-response.json` / `revoke-response.json`: `POST`/`DELETE /v1/contacts/{id}/relationships/offers`
  answer **200** `application/vnd.api+json` with the contact's offer identifiers after the change
- `revoke-not-held-response.json`: revoking an offer the contact doesn't hold is a **400** (expiry confirms and treats it as done)
- `contacts-search-empty.json`: `filter[search]` for a contact created moments earlier (search lags creation)
