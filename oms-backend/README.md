# Order Management System

An assessment-ready order management system built with Laravel 13, Sanctum, MySQL, and Vue 3.

## Requirements

- PHP 8.3 and Composer
- MySQL 8+
- Node.js 22.18+ and npm

## Features

- Sanctum login/logout and role-based API authorization
- Admin, manager, and staff roles
- Customer and product CRUD with safe deactivation
- Paginated, searchable, filterable, and sortable order listing
- Backend-calculated order totals and item price snapshots
- Status transitions, assignment, status history, notes, notifications, and audit logs
- Transactional stock reservation on confirmation and restoration on cancellation
- Date-scoped dashboard metrics and daily/weekly/monthly revenue trends
- Seeded demo users and realistic operational data

## Demo credentials

| Role | Email | Password |
|---|---|---|
| Admin | admin@oms.test | password |
| Manager | manager@oms.test | password |
| Staff | staff1@oms.test | password |

These are local assessment accounts only. Change or remove them before any non-local deployment.

## Local setup

Create a MySQL database named `oms`, then configure its credentials in `oms-backend/.env`.

Backend, from the repository root:

```powershell
cd oms-backend
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

`migrate:fresh` drops existing tables; use it only for a new/local assessment database. The seed creates 5 users, 100 customers, 50 products, and 520 orders.

In another terminal:

```powershell
cd oms-frontend
npm install
npm run dev -- --host 127.0.0.1 --port 5173
```

The frontend API URL defaults to `http://127.0.0.1:8000/api` in `oms-frontend/src/api.ts`.

## API

All routes except login require a Sanctum bearer token. Login accepts `email` and `password`; the token is returned at `data.token`. Send it as `Authorization: Bearer <token>` on subsequent requests. JSON responses use a `success` flag and `data` payload; validation failures return HTTP 422.

### Routes

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/login` | Authenticate and issue token |
| POST | `/api/logout` | Revoke current token |
| GET | `/api/user` | Current user |
| GET, POST | `/api/customers` | List or create customers |
| GET, PUT, DELETE | `/api/customers/{customer}` | View, update, or remove customer |
| GET, POST | `/api/products` | List or create products |
| GET, PUT, DELETE | `/api/products/{product}` | View, update, or remove product |
| GET, POST | `/api/orders` | List or create orders |
| GET, PUT, DELETE | `/api/orders/{order}` | View, update, or remove order |
| POST | `/api/orders/{order}/status` | Change status |
| POST | `/api/orders/{order}/assign` | Assign staff |
| POST | `/api/orders/{order}/notes` | Add an order note |
| GET | `/api/users/assignable` | List assignable staff |
| GET | `/api/dashboard` | Date-scoped metrics and revenue trend |
| GET | `/api/notifications` | List current user's notifications |
| POST | `/api/notifications/read-all` | Mark current user's notifications read |
| POST | `/api/notifications/{notification}/read` | Mark one owned notification read |

### Create order

`POST /api/orders` requires an active `customer_id`, `priority`, `order_date`, and at least one active product item:

```json
{
  "customer_id": 1,
  "priority": "normal",
  "order_date": "2026-11-24",
  "items": [
    { "product_id": 1, "quantity": 2, "discount_cents": 0 }
  ]
}
```

Optional fields include `status` (`draft`, `pending`, or `confirmed`), `assigned_to`, `tax_rate` (percentage integer), `discount_cents`, and `status_remarks`. Amounts use integer cents. Prices and totals are calculated by the server, not accepted from the client.

Status changes use `POST /api/orders/{order}/status` with `status` and optional `remarks`. Valid transitions are draft → pending → confirmed → processing → ready → completed, with cancellation permitted before completion. Completed and cancelled orders are terminal.

### Filtering

- Orders: `search`, `status`, `priority`, `customer_id`, `assigned_to` (including `unassigned`), `from_date`, `to_date`, `sort` (`order_date`, `total_cents`, `status`, `priority`), `direction` (`asc|desc`), `page`, and `per_page` (1–100).
- Customers/products: `search`, `is_active`, `per_page`.
- Dashboard: `from_date`, `to_date`, and `group_by=day|week|month`. If only `to_date` is provided, the default 30-day window ends on that date.

### Roles and inventory

- Admins can manage customers/products and create, edit, assign, and delete cancelled orders.
- Managers can create/edit orders, assign staff, and change order statuses.
- Staff can view and update statuses/add notes only on orders assigned to them.
- Customer/product records referenced by orders are deactivated instead of deleted.
- Stock is reserved when an order is confirmed; cancelling a stock-committed order restores it. These changes are transactional.

## Architecture

`OrderService` owns order creation and updates, total recalculation, status transitions, and stock changes. Currency is stored in integer cents. MySQL is the operational database; automated feature tests use in-memory SQLite. See [API_DOCUMENTATION.md](API_DOCUMENTATION.md) for the full endpoint reference and [TECHNICAL_NOTES.md](TECHNICAL_NOTES.md) for architecture, performance, concurrency, security, and known future work.

## Verification

```powershell
cd oms-backend
php artisan test
vendor\bin\pint.bat --test

cd ..\oms-frontend
npm run build
```
