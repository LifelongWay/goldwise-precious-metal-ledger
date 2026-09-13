# Goldwise — Precious Metal Order Ledger

A small Symfony REST API for recording precious-metal BUY/SELL transactions and
calculating a user's current position. Built for the Goldwise take-home
challenge — deliberately kept small: no auth, no UI, no live pricing.

## Stack

PHP 8.4, Symfony 8.1, Doctrine ORM/Migrations, PostgreSQL 16. Everything runs
in Docker — the host machine needs nothing but Docker.

## Running it

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

The API is now listening on `http://localhost:8000`.

## Running the tests

```bash
docker compose exec app php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec app php bin/phpunit
```

The test suite runs against a real Postgres database (`app_test`), not
mocks/SQLite — the concurrency guarantee in particular only means anything
when it's proven against the real database engine that provides it.

## API

Full request/response schemas: [`openapi.yaml`](openapi.yaml) /
[`openapi.json`](openapi.json).

### Create a transaction

```bash
  curl -X POST http://localhost:8000/users/123/transactions \
    -H "Content-Type: application/json" \
    -d '{"metal":"GOLD","side":"BUY","quantity":2.5,"price":3200,"currency":"GBP"}'
# 201
# {"id":1,"userId":123,"metal":"GOLD","side":"BUY","quantity":"2.50000000","price":"3200.00000000","currency":"GBP","timestamp":"2026-09-12T22:07:01+00:00"}
```

An optional `Idempotency-Key` header makes retries safe:

```bash
curl -X POST http://localhost:8000/users/123/transactions \
  -H "Content-Type: application/json" -H "Idempotency-Key: 3f9c2b" \
  -d '{"metal":"GOLD","side":"BUY","quantity":2.5,"price":3200,"currency":"GBP"}'
# replaying with the same key returns the original transaction rather than creating a second one
```

A SELL that would take the position negative is rejected:

```bash
curl -X POST http://localhost:8000/users/123/transactions \
  -H "Content-Type: application/json" \
  -d '{"metal":"GOLD","side":"SELL","quantity":100,"price":3200,"currency":"GBP"}'
# 409
# {"error":{"code":"insufficient_position","message":"Cannot sell 100.00000000 GOLD: current position is only 2.50000000."}}
```

Invalid input is rejected with the offending fields listed:

```bash
curl -X POST http://localhost:8000/users/123/transactions \
  -H "Content-Type: application/json" \
  -d '{"metal":"PLATINUM","side":"BUY","quantity":-1,"price":3200,"currency":"GBP"}'
# 400
# {"error":{"code":"validation_failed","message":"Validation failed.","violations":[
#   {"field":"metal","message":"metal must be one of: \"GOLD\", \"SILVER\"."},
#   {"field":"quantity","message":"quantity must be greater than zero."}
# ]}}
```

### Get a user's current position

```bash
curl http://localhost:8000/users/123/positions
# [{"metal":"GOLD","quantity":"2.50000000"}]
```

### Transaction history

```bash
curl "http://localhost:8000/users/123/transactions?metal=GOLD&page=1&limit=20"
# {"data":[...],"meta":{"page":1,"limit":20,"total":1}}
```

`metal` and `side` filter the history; `page`/`limit` paginate it
(`limit` capped at 100, defaults to 20).

## Architecture & decisions

**Ledger, not a mutable balance.** There's a single `transactions` table.
A position is `SUM(BUY qty) − SUM(SELL qty)`, computed on read — there's no
separate `positions` table to keep in sync or let drift. This also gives
"transactions are never silently modified" for free: there are no
PATCH/PUT/DELETE routes, and entity fields are `private readonly`, set once
in the constructor.

**Concurrency: a Postgres advisory lock per user.** The naive
check-then-insert (read position, validate, insert) has a classic race: two
concurrent SELLs can each read the same position, both pass validation, and
jointly oversell. Before doing anything else, `LedgerService::createTransaction`
acquires `pg_advisory_xact_lock(userId)` inside the write's database
transaction. That serializes every write for a given user — the second
request's transaction can't even start reading the position until the first
one has committed (or rolled back) — and the lock releases automatically at
transaction end. See `src/Service/LedgerService.php` and
`tests/Integration/AdvisoryLockConcurrencyTest.php`, which proves the lock
using two independent database connections rather than exercising it through
HTTP (the built-in single-threaded PHP dev server can't produce genuine
concurrent requests — see "Improvements" below).

**Decimals as strings.** `quantity`/`price` are Postgres `NUMERIC(18,8)`.
Doctrine maps that to PHP strings rather than floats, so the exact-precision
`SUM(...)` in `TransactionRepository` happens in the database, and the one
PHP-side comparison (proposed SELL vs. current position) uses `bccomp()`
instead of a floating-point `<`/`>`.

**Idempotency.** An optional `Idempotency-Key` header, scoped per user via a
unique `(user_id, idempotency_key)` constraint. A replay with the same key
returns the original transaction instead of creating a duplicate — handled
inside the same locked transaction, so a retry can't race its own original
request either.

**Errors.** One `kernel.exception` listener (`ApiExceptionListener`) maps
everything to a single JSON envelope: `{"error": {"code", "message", ...}}`,
with `422`-style business rule violations returned as `409 Conflict`
(the request conflicts with the user's current state) and input problems as
`400`.

## Assumptions

- **No user resource.** The challenge explicitly excludes auth; `userId` is
  just an opaque positive integer in the path. Any value is valid — a user
  with no transactions simply has an empty history and `[]` for positions.
  There's nothing to 404 on.
- **Supported currencies** are a small fixed set (`GBP`, `USD`, `EUR`) — this
  is a ledger, not an FX service, so the list is just an enum, not
  configurable or backed by a currency table.
- **Quantity/price accept either a JSON number or a JSON string**
  (`2.5` or `"2.5"`). The spec's own example sends a bare number, which JSON
  forces through a PHP float before this API ever sees it — inherent to
  JSON, not a choice made here, though a double's ~15-17 significant digits
  make this a non-issue for realistic bullion quantities. Sending the value
  as a string instead (`"2.5"`) avoids the float step entirely: it goes
  straight through `bcmath` to the `NUMERIC(18,8)` column. See
  `CreateTransactionRequest::toDecimalString()` and
  `testQuantityAndPriceAcceptStringDecimalsWithoutFloatRounding`.
- **A position can be reported as zero.** If a user's BUYs and SELLs for a
  metal net to zero, that metal still appears in `GET /positions` with
  `quantity: "0.00000000"` rather than being hidden — it's still meaningful
  history, not "no position".
- **The advisory lock is per-user, not per-user-per-metal.** Simpler, and
  writes across metals for the same user are rare enough at this scale that
  serializing them costs nothing meaningful.

## What I'd change for production

- **Real authentication/authorization** — right now anyone can post as any
  `userId`.
- **A proper multi-worker app server** (php-fpm + nginx, or FrankenPHP)
  instead of the single-threaded `php -S` dev server used here for
  simplicity — needed both for real throughput and to let true concurrent
  requests actually reach the app at the same time.
- **Cursor-based pagination** for transaction history once histories get
  large — offset pagination degrades on deep pages.
- **An audit/event trail** for corrections: today a mistaken transaction can
  only be offset by recording the opposite trade (by design — history is
  immutable), but a production system would want a first-class "correction"
  concept that still preserves the original record.
- **Structured logging + metrics** around the advisory-lock path in
  particular, since lock contention is exactly the kind of thing you want
  visibility into before it becomes an incident.
- **Rate limiting** on the write endpoint.
- **OpenAPI served from the app** (e.g. via nelmio/api-doc-bundle) instead of
  the hand-written, separately-maintained `openapi.yaml`/`openapi.json` here.
