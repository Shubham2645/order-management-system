# Technical Notes

## Architecture

- Controllers handle HTTP validation and response contracts.
- `OrderService` owns order creation and updates, recalculation, transitions, and stock changes.
- Eloquent relationships, Form Requests, service-layer domain logic, transactions, and audit records provide separation and extensibility.
- Sanctum protects APIs and staff access is restricted to assigned orders.

## Major decisions

- Vue 3 was used for the frontend because it was already scaffolded.
- Pinia stores authentication state; server data is fetched per page.
- MySQL provides the operational store. SQLite is unsuitable for the intended production concurrency.
- Integer cents avoid floating-point currency errors.
- Confirmation is the stock commitment point. This keeps drafts and pending orders reversible without inventory impact.
- Editing confirmed/processing/ready orders reconciles old and new stock reservations atomically. Tax-only edits preserve the original item price snapshots.
- Completed and cancelled statuses are terminal to prevent backward or nonsensical transitions.

## Performance

- Orders, products, and customers have indexes for expected filters.
- Order detail data is eager loaded.
- Listing APIs use database-side filtering and pagination.
- Dashboard aggregates use SQL rather than loaded collections.
- Dashboard date ranges and daily/weekly/monthly revenue buckets are aggregated in SQL with driver-specific grouping expressions.
- Future scale work includes read replicas, Redis caching, cursor pagination for large feeds, partitioning the orders table, and asynchronous notification delivery.

## Security

- Sanctum bearer authentication protects APIs.
- Role checks are enforced in both order and customer/product controllers.
- Staff cannot access unassigned order detail or status operations.
- Laravel validation, prepared Eloquent queries, mass-assignment protection, and JSON API responses reduce common risks.
- Authentication uses password hashing and rate limiting.
- Secrets are excluded from source and demo credentials are seed-only.

## Concurrency

Creation, updates, and status changes use database transactions. Stock and order rows are locked before mutation. A conflict is reported and the transaction rolls back; a future version can add optimistic `version` checks and queue-based notifications.

## AI usage

AI was used for scaffolding, model/controller generation, seed data, UI structure, test ideas, and documentation. Generated code was reviewed and modified for domain rules, authentication, stock, transitions, and MySQL compatibility.

## Future improvements

- Add OpenAPI/Postman artifacts for generated client integration.
- Expand automated coverage for less common status and inventory failure cases.
- Move notification delivery to queues and add optional Redis-backed caching.
- Add PDF/CSV export, containerized setup, and CI/CD deployment workflows.
