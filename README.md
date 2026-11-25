# Order Management System (OMS)

A web-based order management system for handling customers, products, order fulfillment, inventory, and operational reporting. The project is built with Laravel 13, Sanctum, MySQL, and Vue 3.

## Features

- Role-based access for admins, managers, and staff
- Customer and product management with safe deactivation of records linked to orders
- Order creation, editing, assignment, search, filters, sorting, and pagination
- Server-calculated order totals stored as integer cents
- Order status history, internal notes, notifications, and audit records
- Inventory reservation on confirmation and restoration on eligible cancellation
- Dashboard metrics with date filtering and daily, weekly, or monthly revenue trends
- Seeded demo data for local evaluation

## Technology

| Area | Technology |
|---|---|
| Backend | PHP 8.3, Laravel 13 |
| Authentication | Laravel Sanctum |
| Database | MySQL 8+ |
| Frontend | Vue 3, TypeScript, Vite |
| Automated tests | PHPUnit with in-memory SQLite |

## Run locally

### Prerequisites

- PHP 8.3 and Composer
- MySQL 8+
- Node.js 22.18+ and npm

### 1. Prepare the database and backend

Create a MySQL database named `oms`. In PowerShell, from the repository root:

```powershell
cd oms-backend
$env:Path = "C:\laragon\bin\php\php-8.3.37-Win32-vs16-x64;$env:Path"
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
composer install
```

If you do not use this Laragon PHP version, adjust the executable directory above or omit the line if PHP is already on `PATH`. Set the MySQL credentials in `oms-backend/.env`, then run:

```powershell
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

> `migrate:fresh` drops existing database tables. Use it only with a new or disposable local database.

### 2. Start the frontend

Open a second terminal at the repository root:

```powershell
cd oms-frontend
npm install
npm run dev -- --host 127.0.0.1 --port 5173
```

### 3. Open the app

Visit **http://127.0.0.1:5173** in your browser and sign in:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@oms.test` | `password` |
| Manager | `manager@oms.test` | `password` |
| Staff | `staff1@oms.test` | `password` |

Use **Orders** to browse the seeded orders, or open **http://127.0.0.1:5173/orders/new** to create an order. Demo credentials are for local assessment use only.

## Project documentation

- [API documentation](oms-backend/API_DOCUMENTATION.md)
- [Backend setup and execution guide](oms-backend/README.md)
- [Technical notes: architecture, concurrency, and security](oms-backend/TECHNICAL_NOTES.md)

## Run checks

Backend tests and PHP formatting:

```powershell
cd oms-backend
php artisan test
vendor\bin\pint.bat --test
```

Frontend type-check and production build:

```powershell
cd oms-frontend
npm run build
```

## Repository structure

```text
oms-backend/
  app/                 API controllers, requests, models, and order service
  database/            Migrations, factories, and seeders
  tests/               Backend feature and unit tests
oms-frontend/
  src/                 Vue application, routes, stores, and views
```
