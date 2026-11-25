# OMS API Documentation

## Overview

- Base URL for local development: `http://127.0.0.1:8000/api`
- Request/response format: JSON
- Authentication: Laravel Sanctum bearer token, except `POST /login`
- Amounts are represented as integer cents.

Successful responses generally have the shape:

```json
{
  "success": true,
  "data": {}
}
```

Paginated endpoints return Laravel pagination metadata inside `data`. Validation errors return HTTP 422 with an `errors` object. Missing or invalid authentication returns HTTP 401; role or ownership violations return HTTP 403.

## Authentication

### `POST /login`

Request:

```json
{
  "email": "admin@oms.test",
  "password": "password"
}
```

Response data includes a Sanctum `token` and the authenticated `user`. Send the token on protected routes:

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

### `POST /logout`

Revokes the current bearer token.

### `GET /user`

Returns the authenticated user.

## Customers

| Method | Path | Purpose |
|---|---|---|
| GET | `/customers` | Paginated customer list |
| POST | `/customers` | Create customer (admin) |
| GET | `/customers/{customer}` | Customer details, order counts/value, recent orders |
| PUT | `/customers/{customer}` | Update customer (admin) |
| DELETE | `/customers/{customer}` | Delete or deactivate customer (admin) |

List query parameters: `search`, `is_active`, `per_page`.

Create fields: required `name`, `email`; optional `phone`, `address`.
Update accepts those fields and optional `is_active`.

Customers linked to orders are deactivated rather than deleted.

## Products

| Method | Path | Purpose |
|---|---|---|
| GET | `/products` | Paginated product list |
| POST | `/products` | Create product (admin) |
| GET | `/products/{product}` | Product details |
| PUT | `/products/{product}` | Update product (admin) |
| DELETE | `/products/{product}` | Delete or deactivate product (admin) |

List query parameters: `search`, `is_active`, `per_page`.

Create fields: required `name`, `sku`, `price_cents`, `stock_quantity`; optional `is_active`. Update accepts any of those fields. Products referenced by order items are deactivated rather than deleted.

## Orders

| Method | Path | Purpose |
|---|---|---|
| GET | `/orders` | Filtered, sorted, paginated order list |
| POST | `/orders` | Create order (admin or manager) |
| GET | `/orders/{order}` | Order details, items, history, notes, audit entries |
| PUT | `/orders/{order}` | Update a non-terminal order (admin or manager) |
| DELETE | `/orders/{order}` | Delete a cancelled order (admin only) |
| POST | `/orders/{order}/status` | Change status |
| POST | `/orders/{order}/assign` | Assign active staff member (admin or manager) |
| POST | `/orders/{order}/notes` | Add note |
| GET | `/users/assignable` | List active staff available for assignment (admin or manager) |

### Create order

Required fields: active `customer_id`, `priority` (`low`, `normal`, `high`, `urgent`), `order_date`, and one or more active product items.

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

Optional fields: `assigned_to`, initial `status` (`draft`, `pending`, or `confirmed`), `tax_rate` (integer percentage), `discount_cents`, and `status_remarks`. Item fields are `product_id`, `quantity`, and optional `discount_cents`. Client-supplied prices and totals are not used; the server calculates and snapshots them.

### Update order

`PUT /orders/{order}` accepts any of `customer_id`, `assigned_to`, `priority`, `order_date`, `tax_rate`, `discount_cents`, and `items`. When `items` is provided, it replaces the order's item set. Completed and cancelled orders cannot be edited.

### Change status

Request:

```json
{
  "status": "confirmed",
  "remarks": "Stock checked"
}
```

Allowed transitions:

```text
draft -> pending -> confirmed -> processing -> ready -> completed
```

Cancellation is allowed before completion. Completed and cancelled orders are terminal. Stock is reserved on confirmation and restored when a stock-committed order is cancelled.

Staff can view, change status, and add notes only for orders assigned to them. Admins and managers can assign orders and manage order details.

### Order list filters

| Parameter | Values/meaning |
|---|---|
| `search` | Order number, customer, assignee, or product text |
| `status` | `draft`, `pending`, `confirmed`, `processing`, `ready`, `completed`, `cancelled` |
| `priority` | `low`, `normal`, `high`, `urgent` |
| `customer_id` | Customer database ID |
| `assigned_to` | Staff user ID or `unassigned` |
| `from_date`, `to_date` | Inclusive order-date range (`YYYY-MM-DD`) |
| `sort` | `order_date`, `total_cents`, `status`, `priority` |
| `direction` | `asc` or `desc` |
| `page` | Page number, starting at 1 |
| `per_page` | Page size from 1 to 100 |

Staff list results are automatically limited to orders assigned to the authenticated user.

## Dashboard

### `GET /dashboard`

Query parameters:

- `from_date`, `to_date`: inclusive date range (`YYYY-MM-DD`)
- `group_by`: `day`, `week`, or `month`

If dates are omitted, the dashboard uses the last 30 days ending today. If only `to_date` is provided, the 30-day window ends on that date.

The response includes the selected date range and grouping, total and per-status order counts, completed-order revenue and average value in cents, total customers, active products, a status distribution, and a revenue trend.

## Notifications

| Method | Path | Purpose |
|---|---|---|
| GET | `/notifications` | Paginated notifications for the current user |
| POST | `/notifications/read-all` | Mark current user's notifications as read |
| POST | `/notifications/{notification}/read` | Mark an owned notification as read |
