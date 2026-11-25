# OMS frontend

Vue 3 and TypeScript interface for the Laravel OMS API. It includes the dashboard, searchable order/customer/product listings, order create/edit and detail workflows, customer profile statistics, and admin-only customer/product management.

## Setup

```powershell
npm install
npm run dev -- --host 127.0.0.1 --port 5173
```

The API base URL defaults to `http://127.0.0.1:8000/api` in `src/api.ts`. Start the Laravel API separately; see [the backend setup and API guide](../oms-backend/README.md).

## Checks

```powershell
npm run build
```

This runs Vue/TypeScript type-checking and creates a production build.

## Local demo login

- Admin: `admin@oms.test` / `password`
- Manager: `manager@oms.test` / `password`
- Staff: `staff1@oms.test` / `password`
