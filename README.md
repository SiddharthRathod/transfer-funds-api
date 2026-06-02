# Transfer Funds API

A production-ready REST API for fund transfers between accounts, built with PHP 8.4, Symfony 7, MySQL, and Redis.

---

## Project Overview

This API provides a single, reliable endpoint for transferring funds between accounts. The design prioritises correctness under concurrent load: it uses Redis distributed locking to reject duplicate in-flight requests, MySQL pessimistic row locking to prevent race conditions at the database level, and SERIALIZABLE transaction isolation to eliminate phantom reads.

## Assumptions

- Account balances cannot become negative.
- Transfers are processed synchronously.
- Amount precision is fixed at 2 decimal places.
- Redis is available for distributed locking.
- Transfer references are globally unique.

---

## Architecture Overview

```
HTTP Request
     │
     ▼
TransferController          — validates input, maps HTTP ↔ domain exceptions
     │
     ▼
TransferService             — orchestrates locking, transaction, and balance mutation
     │
  ┌──┴──────────────────────────┐
  │                             │
  ▼                             ▼
Redis Lock                  MySQL Transaction (SERIALIZABLE)
(distributed, TTL 10s)      ├─ SELECT … FOR UPDATE (ascending ID order)
                            ├─ Account::hasSufficientBalance()
                            ├─ Account::debit() / Account::credit()
                            └─ INSERT transfers
```

### Key design decisions

- **Redis lock before DB transaction** — fast-fail at the application layer; avoids opening a DB connection for duplicate requests.
- **Ascending ID lock order** — prevents A→B / B→A deadlocks by always locking the lower ID first regardless of transfer direction.
- **SERIALIZABLE isolation** — prevents phantom reads between the balance check and the write within the same transaction.
- **Domain methods on Account** — `credit()`, `debit()`, `hasSufficientBalance()` encapsulate BCMath arithmetic inside the entity, keeping the service free of monetary calculation details.
- **BCMath throughout** — all arithmetic uses `bcadd`, `bcsub`, `bccomp` with scale 2. Floating-point types are never used for money.

---

## Database Design

```
accounts
────────
id          INT AUTO_INCREMENT PK
name        VARCHAR(255)
balance     DECIMAL(15,2)

transfers
─────────
id              INT AUTO_INCREMENT PK
from_account_id INT FK → accounts.id
to_account_id   INT FK → accounts.id
amount          DECIMAL(15,2)
reference       VARCHAR(64) UNIQUE    ← TRX-{uuid4}
status          VARCHAR(20)           ← pending | completed | failed
created_at      DATETIME
```

`reference` is unique — it can be used for idempotency checks by callers.

---

## Transfer Flow

```
1. Validate amount > 0, fromAccountId ≠ toAccountId
2. Acquire Redis lock: transfer-account-{fromAccountId} (TTL 10s)
   └─ If not acquired → 409 ConcurrentTransferException
3. Set transaction isolation to SERIALIZABLE
4. BEGIN TRANSACTION
5. SELECT … FOR UPDATE accounts WHERE id = min(from, to)
6. SELECT … FOR UPDATE accounts WHERE id = max(from, to)
7. Re-fetch sender + receiver via Doctrine identity map
8. sender.hasSufficientBalance(amount) → false → 422 InsufficientFundsException
9. sender.debit(amount)
10. receiver.credit(amount)
11. INSERT Transfer (status = completed, reference = TRX-{uuid})
12. COMMIT
13. Release Redis lock (finally block)
```

---

## Redis Locking Strategy

The `symfony/lock` component is configured with a named `transfer` store backed by Redis (`lock.transfer.factory`). Each transfer acquires a key of the form `transfer-account-{fromAccountId}` with a 10-second TTL.

- The lock is acquired **before** the DB transaction opens, so a rejected request never touches MySQL.
- The lock is released in a `finally` block, ensuring it is always freed even if the DB transaction fails.
- TTL of 10 seconds is intentionally generous — the full transfer cycle (including DB round-trips under load) completes well within 1 second in practice.
- If Redis is unavailable, the application falls back to the MySQL pessimistic lock alone, which still prevents overdrafts — but concurrent duplicate requests may both enter the DB transaction.

---

## How To Run

### Prerequisites

- Docker Desktop or Docker Engine + Compose v2
- Ports `8000`, `8080`, `3306`, `6379` available locally

### Start

```bash
git clone <repo>
cd transfer-funds-api
docker compose up -d
```

### Install dependencies

```bash
docker compose exec php composer install
```

---

## Docker Commands

```bash
# Start all containers
docker compose up -d

# Stop all containers
docker compose down

# Rebuild PHP image (e.g. after Dockerfile change)
docker compose build php && docker compose up -d php

# View logs
docker compose logs -f php
docker compose logs -f nginx

# Open a shell in the PHP container
docker compose exec php bash
```

---

## Migration Commands

```bash
# Create a new migration from entity changes
docker compose exec php php bin/console make:migration

# Run all pending migrations
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Check migration status
docker compose exec php php bin/console doctrine:migrations:status
```

---

## Fixture Commands

```bash
# Seed Alice (1000.00), Bob (500.00), Charlie (2000.00)
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction

# Seed without purging existing data
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction --append
```

---

## Running Tests

```bash
# Run all tests
docker compose exec php php bin/phpunit

# Run with verbose output
docker compose exec php php bin/phpunit --testdox

# Run only controller tests
docker compose exec php php bin/phpunit tests/Integration/Controller/

# Run only service tests
docker compose exec php php bin/phpunit tests/Integration/Service/
```

The test database (`paysera_test`) is created automatically by Symfony's `dbname_suffix` config. Each test class manages its own data via `tearDown` cleanup — no global truncation is needed.

---

## API Examples

### POST /api/transfers

**Success — 201 Created**

```bash
curl -s -X POST http://localhost:8000/api/transfers \
  -H "Content-Type: application/json" \
  -d '{"fromAccountId": 1, "toAccountId": 2, "amount": "100.00"}'
```

```json
{
  "success": true,
  "reference": "TRX-6270480f-6091-4f6b-ba10-1ce206b66894",
  "status": "completed"
}
```

**Insufficient funds — 422**

```bash
curl -s -X POST http://localhost:8000/api/transfers \
  -H "Content-Type: application/json" \
  -d '{"fromAccountId": 2, "toAccountId": 1, "amount": "9999.00"}'
```

```json
{
  "success": false,
  "message": "Account 2 has insufficient funds. Balance: 400.00, required: 9999.00."
}
```

**Account not found — 404**

```json
{
  "success": false,
  "message": "Account with ID 999 not found."
}
```

**Concurrent transfer in progress — 409**

```json
{
  "success": false,
  "message": "Another transfer is already in progress for this account"
}
```

**Validation error — 400**

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": ["toAccountId is required.", "amount is required."]
}
```

### GET /health

```bash
curl http://localhost:8000/health
```

```json
{
  "status": "ok",
  "service": "transfer-funds-api"
}
```

---

## Postman Example

1. Create a new **POST** request to `http://localhost:8000/api/transfers`
2. Set **Body** → **raw** → **JSON**
3. Paste:
```json
{
  "fromAccountId": 1,
  "toAccountId": 2,
  "amount": "50.00"
}
```
4. Send — expect **201 Created**

To test validation, remove `amount` from the body and send again — expect **400** with `errors` array.

---

## Concurrency Testing Example

Fire two transfers simultaneously from the same sender using shell background jobs:

```bash
curl -s -X POST http://localhost:8000/api/transfers \
  -H "Content-Type: application/json" \
  -d '{"fromAccountId":1,"toAccountId":2,"amount":"100.00"}' &

curl -s -X POST http://localhost:8000/api/transfers \
  -H "Content-Type: application/json" \
  -d '{"fromAccountId":1,"toAccountId":2,"amount":"100.00"}' &

wait
```

Expected output: one **201** and one **409** (Redis lock rejection). The sender balance will reflect exactly one debit — never two.

To test the DB-level race condition specifically (bypassing the Redis lock), use the integration test `testConcurrentTransfersDoNotOverdraft` which spawns two PHP processes simultaneously against the real database.

---

## Future Improvements

**Features**
- `GET /api/accounts/{id}` — fetch account balance
- `GET /api/transfers/{reference}` — fetch transfer by reference (idempotency)
- Multi-currency support with exchange rate integration
- Webhook notifications on transfer completion

**Reliability**
- Outbox pattern — persist a pending transfer record before executing, mark completed after; enables recovery from crashes mid-transfer
- Idempotency key header (`X-Idempotency-Key`) — allow clients to safely retry without double-debiting
- Circuit breaker around Redis — graceful degradation if lock store is unavailable

**Security**
- API authentication (JWT or API key)
- Rate limiting per account/IP via `symfony/rate-limiter`
- Input length and type hardening at the network layer (WAF)
- Audit log of all transfer attempts (separate append-only table)

**Scalability**
- Read replica for account lookups (write path stays on primary)
- Async transfer processing via Symfony Messenger for high-volume scenarios
- Horizontal PHP-FPM scaling behind a load balancer (Redis lock is already cluster-safe)

**Observability**
- Structured JSON logging via Monolog with a request correlation ID
- Prometheus metrics (transfer count, latency, failure rate)
- Distributed tracing (OpenTelemetry)

**Infrastructure**
- GitHub Actions CI pipeline (lint, test, static analysis)
- PHPStan at level 8
- Docker image for production with `opcache` and FPM tuning

---

## Time Spent

| Task | Time |
|---|---|
| Project setup (Docker, Symfony skeleton) | ~30 min |
| Entity design + migrations | ~20 min |
| TransferService + locking | ~45 min |
| Controller + DTO + validation | ~30 min |
| Redis distributed locking | ~30 min |
| Integration tests (service + HTTP) | ~60 min |
| Logging + domain refactor | ~20 min |
| Fixtures + health endpoint | ~15 min |
| README | ~20 min |
| **Total** | **~4 hours** |

---

## AI Tools Used

**Amazon Q Developer** (IDE plugin) was used throughout this project for:

- Generating entity, service, controller, and test scaffolding
- Diagnosing runtime errors (Doctrine ORM 3.x API changes, DBAL 4.x DSN parsing, PHP 8.4 native lazy objects)
- Iterating on the race condition test design (child process bootstrapping, LockFactory wiring)
- Drafting this README

All generated code was reviewed, understood, and validated by running the full test suite (`20/20 passing`) before submission. The architecture decisions, locking strategy, and BCMath usage reflect deliberate engineering choices rather than blind code generation.
