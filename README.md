<h1 align="center">askmydocs-connector-evernote</h1>

<p align="center">
  <strong>Evernote connector for AskMyDocs — dual-mode OAuth2 sync + .enex bulk import with native ENML→markdown rendering.</strong><br/>
  Drop-in Laravel package. <code>composer require</code> it from any AskMyDocs install and the Evernote connector appears in the admin UI on the next request.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-evernote/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-evernote/tests.yml?branch=main&label=tests"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-evernote"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-evernote.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-evernote"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-evernote.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [AI vibe-coding pack included](#-ai-vibe-coding-pack-included)
4. [Architecture at a glance](#architecture-at-a-glance)
5. [Installation](#installation)
6. [Credential setup (junior-proof, step by step)](#credential-setup-junior-proof-step-by-step)
7. [Activation inside AskMyDocs](#activation-inside-askmydocs)
8. [`.enex` bulk import](#enex-bulk-import)
9. [What gets ingested](#what-gets-ingested)
10. [Sync semantics](#sync-semantics)
11. [Testing](#testing)
12. [Live testsuite](#live-testsuite)
13. [Troubleshooting](#troubleshooting)
14. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow — but a lot of the institutional knowledge people actually want to query lives in Evernote.

This package is the smallest possible surface for shipping that integration:

- An `EvernoteConnector` that implements `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`.
- An `EnmlToMarkdown` converter that flattens Evernote's strict-XHTML ENML format into clean GitHub-flavoured markdown — paragraphs, headings, bulleted / numbered lists, GitHub-style task lists from `<en-todo>`, tables, fenced code with language hints, quotes, inline-link annotations.
- An `EnexImporter` that streams Evernote's `.enex` export format and ingests each `<note>` individually — handy when an operator wants to backfill from a personal export without wiring an OAuth app.
- A composer.json that auto-registers via `extra.askmydocs.connectors`. Zero edits to your host app's config required.

> **`composer require padosoft/askmydocs-connector-evernote`. Done.**

## Features

- 🔌 **Zero-config installation** — composer-extra discovery auto-registers the connector at boot.
- 🔐 **OAuth2 + state-token round-trip** — single-use, replay-resistant CSRF state with 600s TTL.
- ♻️ **Incremental sync** — Evernote's `updated:<UTC-zulu>` search-grammar filter; daily syncs cost one round-trip on quiet accounts.
- 🗑️ **Deletion reconciliation** — notes with `deleted != null` route through the host's deletion service via `softDeleteByRemoteId('evernote_note_guid', ...)`.
- 📥 **`.enex` bulk import** — stream-parse Evernote export files with bounded memory; ingest 500 MB exports without blowing the heap.
- 🧠 **Source-aware metadata** — tags, notebook, source URL, reminder state, last-modified all surface to the host's reranker via `SourceAwareMetadataBuilder`.
- 🧩 **ENML-aware markdown** — `<en-todo>` becomes `- [x]` / `- [ ]`, `<en-media>` emits skip markers operators can audit, `<en-crypt>` blocks degrade gracefully.
- 🚦 **Failure-loud exception taxonomy** — 401 / 403 → `ConnectorAuthException`, 5xx / 429 → `ConnectorApiException`, malformed `.enex` → `InvalidEnexException` (HTTP-422-ready).
- 🏢 **Per-tenant isolated** — every credential read and ingestion dispatch is scoped to the active `TenantContext`.
- 🧪 **Test-friendly** — pure-PHP unit tests for the ENML converter, `Http::fake()` feature tests for the connector + importer, opt-in live test that hits real `sandbox.evernote.com` when `CONNECTOR_EVERNOTE_LIVE=1`.

## 🚀 AI vibe-coding pack included

This package was built with a vibe-coding pack of Claude Code skills and rules (`.claude/` directory in the parent AskMyDocs repo) that codify the architectural invariants — the IoC contract that keeps this package standalone-agnostic, the Evernote API quirks the connector navigates, the failure-loud exception taxonomy, the ENEX streaming contract.

If you're using Claude Code to fork or extend this package, point the agent at the parent repo's `.claude/` pack and it stays inside the invariants automatically. No tribal-knowledge drift.

## Architecture at a glance

```
                ┌──────────────────────────────┐
Composer        │ padosoft/askmydocs-          │
require ───────▶│ connector-evernote           │
                │ (this package)               │
                └────────────┬─────────────────┘
                             │
                             │ auto-registered via composer
                             │ extra.askmydocs.connectors
                             ▼
                ┌──────────────────────────────┐
                │ padosoft/askmydocs-connector-│
                │ base v1.1.1+                 │
                │ ConnectorRegistry            │
                └────────────┬─────────────────┘
                             │
                             │ resolves EvernoteConnector
                             ▼
                ┌──────────────────────────────┐
                │ EvernoteConnector::syncFull  │
                │  • POST /v1/notes/search     │
                │  • GET  /v1/notes/{guid}     │
                │  • EnmlToMarkdown            │
                │  • SourceAwareMetadata       │
                └────────────┬─────────────────┘
                             │
                             │ ConnectorIngestionContract
                             │ (IoC bridge — host implements)
                             ▼
                ┌──────────────────────────────┐
                │ Host app (AskMyDocs):        │
                │  • Storage::put → KB disk    │
                │  • IngestDocumentJob         │
                │  • kb_canonical_audit row    │
                │  • PII redactor at boundary  │
                └──────────────────────────────┘
```

The IoC bridge is the key design decision: this package never imports `App\Jobs\IngestDocumentJob`, `App\Models\KnowledgeDocument`, or any other host class. It dispatches every host-side concern through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. The host binds its own implementation in a service provider; this package stays standalone-agnostic so it can run inside AskMyDocs Community Edition, AskMyDocs Pro, or any third-party Laravel app that wants Evernote-backed RAG.

## Installation

```bash
composer require padosoft/askmydocs-connector-evernote
```

The package follows Laravel's auto-discovery convention so no manual provider registration is required. After install, run:

```bash
php artisan vendor:publish --tag=connector-evernote-config   # optional — for env-var overrides
php artisan vendor:publish --tag=connector-evernote-assets   # optional — copies evernote.svg to public/connectors
```

The `connector-base` migrations ship in the parent package (`padosoft/askmydocs-connector-base`) and auto-load via its service provider; no extra `migrate` step is needed.

## Credential setup (junior-proof, step by step)

Evernote uses an OAuth2 flow registered through the developer portal. You need a `client_id`, `client_secret`, and a redirect URI registered with Evernote. Follow EVERY step.

### 1. Pick sandbox or production

- **Sandbox** — `https://sandbox.evernote.com` — for development. Sandbox accounts are free, separate from your real Evernote account, and your real notes are NOT visible.
- **Production** — `https://www.evernote.com` — only after you've validated end-to-end against sandbox.

The rest of this section walks the sandbox flow. Swap the host in step 4 for production.

### 2. Create the Evernote developer integration

1. Open <https://dev.evernote.com/> in your browser. Click **"Get API Key"** (top-right).
2. Sign in with your sandbox Evernote credentials (create a sandbox account at <https://sandbox.evernote.com/Registration.action> if you don't have one).
3. Fill in the API-key request form:
    - **Application name**: `AskMyDocs` (or any label that makes sense)
    - **Application description**: `RAG-backed knowledge ingestion`
    - **Application URL**: your host app's public URL (used for OAuth callback)
    - **Permission type**: pick **"Full Access"** — required because the API key is OAuth-scoped at the protocol level (the connector only uses read endpoints).
4. Submit the form. Evernote emails your API key + secret within ~1 hour (sandbox) or 1–2 business days (production).

### 3. Capture the credentials

From the email — or via <https://dev.evernote.com/> → **"My API Keys"**:

- `consumer key`     → `CONNECTOR_EVERNOTE_CLIENT_ID`
- `consumer secret`  → `CONNECTOR_EVERNOTE_CLIENT_SECRET`

### 4. Write credentials to `.env`

In your AskMyDocs host app's `.env`:

```dotenv
# Sandbox (default — recommended for first install):
CONNECTOR_EVERNOTE_CLIENT_ID=<your-consumer-key>
CONNECTOR_EVERNOTE_CLIENT_SECRET=<your-consumer-secret>
CONNECTOR_EVERNOTE_REDIRECT_URI=https://your-app.example.com/api/admin/connectors/evernote/oauth/callback
CONNECTOR_EVERNOTE_API_BASE=https://sandbox.evernote.com

# Production (only after sandbox validation):
# CONNECTOR_EVERNOTE_API_BASE=https://api.evernote.com
# CONNECTOR_EVERNOTE_OAUTH_AUTHORIZE_URL=https://www.evernote.com/oauth2/authorize
# CONNECTOR_EVERNOTE_OAUTH_TOKEN_URL=https://www.evernote.com/oauth2/token
```

If you're testing OAuth locally and don't have a publicly-routable HTTPS redirect URI, use a tunnel (Cloudflare Tunnel, ngrok, Tailscale Funnel) so Evernote can call your callback.

### 5. Verify (curl)

```bash
curl -s -X POST https://sandbox.evernote.com/shard/s1/v2/users/me \
  -H "Authorization: Bearer <a-real-oauth-token-from-step-2>"
```

If you see `200 OK` with a JSON user payload → you're good. If you see `401 invalid_token` → your token isn't OAuth-issued; complete the OAuth flow inside AskMyDocs first (the admin UI takes care of it).

### 6. Common errors

- `401 invalid_token` — Token never went through the OAuth flow, or the token has been revoked from Evernote's side. Re-install via the admin UI.
- `403 quota_exceeded` — Sandbox accounts have a 100 API calls/hour cap. Wait an hour, or upgrade your sandbox tier.
- `redirect_uri_mismatch` — The exact redirect URI in `.env` must match what you registered on dev.evernote.com (trailing slashes matter).

## Activation inside AskMyDocs

After `composer require` + the env vars above:

1. Run the host app's admin UI.
2. Navigate to **Settings → Connectors**.
3. The **Evernote** card appears with an **Install** button.
4. Click **Install** → browser redirects to Evernote → operator authorises → returns to the admin UI → status flips to `active`.
5. The first full sync fires within the cadence window (default 15 minutes; configurable via `CONNECTOR_DEFAULT_SYNC_CADENCE_MINUTES`). To trigger immediately, click **Sync now**.

## `.enex` bulk import

This package ships `Padosoft\AskMyDocsConnectorEvernote\Support\EnexImporter` as a standalone helper for the case where the operator wants to backfill from an existing `.enex` export instead of wiring an OAuth app.

The package deliberately does NOT register an HTTP controller for this — the upload endpoint needs admin RBAC + audit middleware that vary per host. Wire your own controller and hand the local file path + a `ConnectorInstallation` instance to the importer:

```php
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorEvernote\Support\EnexImporter;
use Padosoft\AskMyDocsConnectorEvernote\Support\InvalidEnexException;

public function importEnex(Request $request, EnexImporter $importer)
{
    $request->validate([
        'enex' => ['required', 'file', 'mimes:enex,xml'],
        'project_key' => ['required', 'string'],
        'installation_id' => ['required', 'integer'],
    ]);

    $installation = ConnectorInstallation::query()
        ->where('id', $request->integer('installation_id'))
        ->where('tenant_id', tenant_id())
        ->where('connector_name', 'evernote')
        ->firstOrFail();

    try {
        $result = $importer->import(
            $request->file('enex')->getRealPath(),
            $installation,
            $request->string('project_key')->toString(),
        );
    } catch (InvalidEnexException $e) {
        return response()->json([
            'error' => 'invalid_enex',
            'message' => $e->getMessage(),
        ], 422);
    }

    return response()->json($result->toArray(), 202);
}
```

`InvalidEnexException` is raised BEFORE any note is written when the file is malformed XML or the root element isn't `<en-export>` — this is the R14 contract (loud failure, never silent success on parse error).

## What gets ingested

For every Evernote note the integration can see:

- **Markdown body** — ENML rendered via `EnmlToMarkdown`. Note title prepended as `# Title` so the host's chunker indexes it.
- **Frontmatter / metadata** captured under `metadata.converter_hints.evernote`:
  - `note_guid`, `notebook_guid`, `notebook` (name)
  - `tags` — note tag names
  - `created`, `updated` — ISO timestamps
  - `source_url`, `reminder_done`
- **`_derived` reranker signals** under `metadata.converter_hints._derived`:
  - `search_tags`, `status_active`, `recency_bucket`

The synthetic MIME `application/vnd.evernote.note+xml` routes the document to the host's Evernote-aware chunker when one is installed.

## Sync semantics

- **Full sync** — `POST /v1/notes/search` with `offset` + `maxNotes=250`, walks until `offset >= totalNotes`. Each note's full ENML body is fetched via `GET /v1/notes/{guid}?withContent=true`, rendered to markdown, dispatched. Safety cap at 200 iterations (~50 000 notes).
- **Incremental sync** — same `/search` call with `filter.words = "updated:YYYYMMDDTHHMMSSZ"`. Evernote returns only notes whose `updated` is greater than `$since` (UTC Zulu format mandatory).
- **Deletion reconciliation** — notes with non-null `deleted` route through `ConnectorIngestionContract::softDeleteByRemoteId('evernote_note_guid', ...)`. The host's deletion service finds the matching `knowledge_documents` row (tenant-scoped) and soft-deletes it.
- **Disconnect** — best-effort revoke call to Evernote's token-revoke endpoint, then local credentials are cleared. Errors from the revoke call are logged but never propagated; local cleanup is always atomic.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite has three flavours:

| Suite     | What it covers                                                                                  | Network |
|-----------|-------------------------------------------------------------------------------------------------|---------|
| Unit      | `EnmlToMarkdown` — pure PHP, ~20 ENML shape cases.                                              | None    |
| Feature   | `EvernoteConnector` + `EnexImporter` against `Http::fake()` and the spy ingestion contract.     | None    |
| Live      | Opt-in — actually hits sandbox.evernote.com. Skipped unless `CONNECTOR_EVERNOTE_LIVE=1`.        | Real    |

CI runs Default (Unit + Feature) against PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

## Live testsuite

The live suite is **opt-in** so CI never pays for real API calls. To run it:

```bash
export CONNECTOR_EVERNOTE_LIVE=1
export CONNECTOR_EVERNOTE_TOKEN=<your-sandbox-oauth-token>
vendor/bin/phpunit --testsuite=Live
```

This calls `/shard/s1/v2/users/me` on sandbox.evernote.com once to validate credentials.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `401 invalid_token` during sync | Token revoked from Evernote-side, OR token was never OAuth-issued | Re-install from the admin UI |
| `403 quota_exceeded` | Sandbox API rate-limit hit (100/hour) | Wait an hour OR validate against production |
| `Evernote OAuth callback state token invalid` | The callback was hit twice OR the cache TTL expired (default 600s) | Restart the install from the admin UI; the state token re-issues on the next click |
| `InvalidEnexException: expected <en-export>` | File isn't an Evernote export | Evernote desktop → File → Export → choose `.enex`. `.json` and `.html` exports won't work |
| Notes ingest with empty body | Note contains only `<en-media>` attachments (images, audio, PDF) | This is by design — AskMyDocs doesn't yet extract binary attachments from Evernote |

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
