# Evernote credential setup

The canonical walkthrough lives in [`README.md` §Credential setup](../README.md#credential-setup-junior-proof-step-by-step) — it covers sandbox account creation, API-key request, OAuth redirect URI registration, and `.env` wiring.

Use this file as a printable cheatsheet when onboarding operators.

## Cheatsheet

1. Create sandbox account: <https://sandbox.evernote.com/Registration.action>
2. Request API key: <https://dev.evernote.com/> → "Get API Key"
3. Wait for the Evernote email (sandbox ~1 h, production 1–2 business days)
4. Copy `consumer key` + `consumer secret` from <https://dev.evernote.com/> → "My API Keys"
5. Write to `.env`:
    - `CONNECTOR_EVERNOTE_CLIENT_ID=<consumer-key>`
    - `CONNECTOR_EVERNOTE_CLIENT_SECRET=<consumer-secret>`
    - `CONNECTOR_EVERNOTE_REDIRECT_URI=https://your-app/api/admin/connectors/evernote/oauth/callback`
    - `CONNECTOR_EVERNOTE_API_BASE=https://sandbox.evernote.com` (production: drop this line or set `https://api.evernote.com`)
6. Install via AskMyDocs admin UI → Settings → Connectors → Evernote → Install
