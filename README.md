# PromptEdit × Pixelforge

A small Laravel 12 + Vue 3 (Inertia) + Tailwind app where signed-in users generate images through the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API and pay Pixelforge's cost **+25%**, computed exactly.

- **Generate** (`/generate`): prompt, size, quality, up to 4 reference image URLs, and a live price range before submitting.
- **History** (`/generations`): live status (queued → processing → done/failed), estimated vs actual price, the image, and the error message. Failed or rejected generations cost $0 and can be retried.
- **Credits** (stretch): fake "Buy $5 of credits". The maximum price is reserved on submit and settled when the generation finishes. You can't submit without enough credits.
- **Admin report** (stretch, `/admin/report`): per UTC day (by `completed_at`) and per user: charged, provider cost and profit, with each day checked against Pixelforge's `GET /v1/usage`.

## Design choices

**Money is stored as integer nanodollars** (`bigint`, 1 USD = 10⁹). Pixelforge reports exact 9-decimal strings, so a nanodollar is its smallest unit. Rates (`"12.50"` per 1M tokens) are parsed as decimal strings into nanodollars per token (`12 500`) without floats. Sums are exact and compare 1:1 with `/v1/usage`'s `cost_usd`. Markup has a single rule, `ceil(cost × 125 / 100)` to the nanodollar, in `PricingService::withMarkup`. With today's rates it is always exact; the rounding only guards against future rates.

**The actual price uses the generation's frozen `rates`** and final `usage` (from the webhook/generation object), not current pricing. Only `succeeded` is billed; `failed`/`rejected` are $0.

**Estimate.** Output tokens (quality × size) and image tokens (765 per reference) are known up front. Prompt tokens (≈ chars/4, ±15%, counted in Unicode characters) and reasoning tokens (min..max per quality) are only known at the end, so the UI shows a **range**, and the max is a real ceiling. The browser computes it live from `/v1/pricing` (cached 10 min). The server recomputes it on submit and never trusts the client.

**Idempotency and submission.**
1. The local row is created **before** calling Pixelforge (status `pending`, with a `uuid`).
2. `SubmitGenerationJob` sends `POST /v1/generations` with `Idempotency-Key: <uuid>` and `metadata.app_generation_id = <uuid>`. Only documented fields are sent, because unknown ones are a 422.
3. Retries reuse the same key, so a timeout or 503 can never create, or bill, a second generation.
4. A webhook that arrives before the POST returns still finds the row through the metadata.

**Errors and limits.**
- `503 overloaded` and `429 rate_limit_exceeded` are retried with `Retry-After`, exponential backoff and jitter, capped at 6 attempts. After that the generation is rejected, free, with a clear message.
- `429 concurrency_limit_exceeded` waits for a free slot. The job also checks the 3-in-flight limit locally before calling.
- `400` content policy, `429` daily limit, `422`, `409` and `401` are final, shown to the user, and $0.
- On network errors the generation stays `pending` and is retried with the same key.

**Webhooks** (`POST /webhooks/pixelforge`, CSRF-excluded). The `Pixelforge-Signature` (`t=…,v1=…`) is verified on the raw body as HMAC-SHA256 of `"t.body"`, compared with `hash_equals`, and rejected if `t` is more than 300 s old. In **one DB transaction** the event is then inserted (unique `event_id`, `insertOrIgnore`) and the generation row is updated under `lockForUpdate`:
- A duplicate delivery inserts nothing and does nothing.
- A crash rolls everything back, we return 500, and Pixelforge retries.

**Ordering.** Every update goes through `GenerationStateMachine`, which is shared by the webhook, the POST response and polling:
- It trusts `data.object.status`, not the event type.
- Status only moves forward (`pending < queued < processing < succeeded|failed`), so a late `processing` after `succeeded` is ignored.
- The charge happens once, on the single transition to `succeeded`. A `unique(generation_id, type)` on the credit ledger is a second guard.

**Polling fallback.** Webhook delivery is not guaranteed, so `generations:reconcile` runs every minute:
- It polls generations still `queued`/`processing` after 2 minutes, up to 10 calls per run so it stays under 60 req/min.
- It re-dispatches stuck `pending` ones. That is safe because the job is unique and reuses the same idempotency key.

The History page polls the server every 3 s (Inertia `usePoll`, `only: ['generations']`) while something is active.

**Code map**

| Concern | File |
|---|---|
| HTTP client | `app/Services/Pixelforge/PixelforgeClient.php` |
| Pricing / money | `app/Services/Pricing/PricingService.php`, `Money.php` |
| Webhook signature | `app/Services/Pixelforge/WebhookSignature.php` |
| Webhook dedup + transaction | `app/Services/Pixelforge/WebhookProcessor.php` |
| Status transitions | `app/Services/Pixelforge/GenerationStateMachine.php` |
| Submission, retries | `app/Jobs/SubmitGenerationJob.php`, `app/Actions/CreateGeneration.php` |
| Polling fallback | `app/Console/Commands/ReconcileGenerations.php` |
| Credits ledger | `app/Services/Billing/CreditLedger.php` |
| Admin report | `app/Http/Controllers/Admin/ReportController.php` |
| Vue pages | `resources/js/pages/Generations/{Create,Index}.vue`, `resources/js/pages/Admin/Report.vue` |

## Local setup

Requirements: PHP 8.2+, Composer, Node 20+. Locally the app uses SQLite. The migrations are portable, so Postgres works in production.

```bash
composer install
cp .env.example .env        # then fill PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET
php artisan key:generate
php artisan migrate
npm install
```

Run these in separate terminals (or use `composer run dev` for server, queue and Vite, then add the scheduler):

```bash
php artisan serve
php artisan queue:work --tries=0    # the job handles its own retries/backoff
php artisan schedule:work           # polling fallback (generations:reconcile)
npm run dev
```

**Webhooks in local dev.** Pixelforge refuses localhost URLs, so expose the app with a tunnel:

```bash
ngrok http 8000      # or: cloudflared tunnel --url http://localhost:8000
```

1. In the Pixelforge dashboard, set your **local** key's webhook URL to `https://<tunnel-host>/webhooks/pixelforge`. Use the exact URL: redirects aren't followed.
2. Copy that key's signing secret into `PIXELFORGE_WEBHOOK_SECRET`.
3. Press "Send test event". You should get a 2xx.

Admin access: register, then run `php artisan app:make-admin you@example.com`.

Tests (Pixelforge is faked with `Http::fake`):

```bash
php artisan test
```

Manual checks with Pixelforge's test keywords: `[succeed]`, `[fail]` (free), `[blocked]` (rejected, free), `[overloaded]` (retries, then rejected), `[slow]`, `[duplicate]` (charged once). Also try 4 generations at once to see the concurrency wait.

## Deploying on Laravel Cloud

1. Push the repo to GitHub, then create an application on cloud.laravel.com from it.
2. Attach a **Postgres** database. Cloud injects the `DB_*` variables.
3. Set environment variables: `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` from a **separate production key**, plus `QUEUE_CONNECTION=database`.
4. Build commands: `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`. Deploy command: `php artisan migrate --force`.
5. Add a **queue worker** (`php artisan queue:work --tries=0`) and enable the **scheduler** (it runs `generations:reconcile` every minute).
6. **Disable hibernation.** A sleeping app doesn't run queued jobs, and a cold start can exceed Pixelforge's 10 s webhook timeout.
7. In the Pixelforge dashboard, set the production key's webhook URL to `https://<app>.laravel.cloud/webhooks/pixelforge`, then send a test event.
8. Register a user, make it admin (`php artisan app:make-admin …` from the Cloud console), buy credits, and leave some generations in place.

## With more time

- Download images to object storage before the 24 h expiry.
- Per-user rate limiting.
- Websocket push instead of polling.
- A refund action in the admin panel.
