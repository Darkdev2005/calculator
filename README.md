# Real-Time Calculator (Laravel + Vue 3 + WebSocket)

Authenticated real-time calculator built with Laravel and Vue 3. Calculation operations are processed only through WebSocket messages (no REST calculation endpoint).

## Stack

- Backend: Laravel 13 (compatible with Laravel 11+ requirement)
- Frontend: Vue 3 + Inertia + Vite
- Database: MariaDB (via Laravel `mysql` driver)
- Realtime: native WebSocket server using Workerman

## Features

- Authentication required for calculator access
- Secure WebSocket authentication token bound to logged-in user
- Supported operations: addition, subtraction, multiplication, division, power, modulo
- Validation and error handling (invalid input, division/modulo by zero, invalid precision)
- Per-user persistent calculation history in database
- Real-time result/history updates across same user's open sessions
- Precision control (`0..10`) for rounded results
- Extra realtime actions: history clear and stats snapshot

## Database schema

Table: `calculation_histories`

- `id`
- `user_id`
- `operation`
- `operand_left`
- `operand_right`
- `result`
- `calculated_at`
- `created_at`
- `updated_at`

## Setup

1. Install dependencies:

```bash
composer install
npm install
```

2. Environment setup:

```bash
cp .env.example .env
php artisan key:generate
```

3. Configure MariaDB in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=calculator
DB_USERNAME=root
DB_PASSWORD=
```

4. Migrate:

```bash
php artisan migrate
```

5. Build frontend:

```bash
npm run build
```

## Running locally

Run these in separate terminals:

1. Laravel app:

```bash
php artisan serve
```

2. Queue (optional for this project):

```bash
php artisan queue:listen
```

3. WebSocket calculator server:

```bash
php artisan calculator:websocket
```

4. Frontend dev server (optional for hot reload):

```bash
npm run dev
```

Open `http://127.0.0.1:8000`, register/login, then use calculator at `/dashboard`.

## WebSocket protocol

Connect URL:

```text
ws://127.0.0.1:8090/calculator?token={encrypted_token}
```

Client -> server messages:

- Calculate:

```json
{
  "action": "calculate",
  "operation": "add|subtract|multiply|divide|power|modulo",
  "left": 12,
  "right": 4,
  "precision": 2
}
```

- Get history:

```json
{
  "action": "history",
  "limit": 20
}
```

- Clear history:

```json
{
  "action": "history.clear"
}
```

- Get stats:

```json
{
  "action": "stats"
}
```

Server -> client messages:

- `connection.ready`
- `connection.error`
- `calculation.result`
- `calculation.error`
- `history.snapshot`
- `history.cleared`
- `stats.snapshot`

## Tests

Run unit tests:

```bash
php artisan test --testsuite=Unit
```

Included edge cases:

- division by zero
- modulo by zero
- invalid operation
- non-numeric operands
- invalid precision

## Security notes

- `/dashboard` is protected by `auth` + `verified` middleware.
- WebSocket connection requires encrypted short-lived token generated for authenticated users.
- Invalid/expired token connections are closed immediately.
