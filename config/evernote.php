<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Evernote connector configuration
|--------------------------------------------------------------------------
|
| Provider settings for `padosoft/askmydocs-connector-evernote`.
|
| The base package merges this block under
| `config('connectors.providers.evernote')`, so concrete connector code
| reads its config via the standard
| `config('connectors.providers.evernote.<key>')` path.
|
| All knobs accept env-var overrides — set them in your host app's
| `.env` (see the package README §Credential setup).
|
*/

return [
    'client_id' => env('CONNECTOR_EVERNOTE_CLIENT_ID'),
    'client_secret' => env('CONNECTOR_EVERNOTE_CLIENT_SECRET'),
    'redirect_uri' => env(
        'CONNECTOR_EVERNOTE_REDIRECT_URI',
        env('APP_URL', 'http://localhost').'/api/admin/connectors/evernote/oauth/callback'
    ),
    // The OAuth2 + REST endpoints. Use sandbox.evernote.com for development,
    // www.evernote.com / api.evernote.com for production.
    'oauth_authorize_url' => env(
        'CONNECTOR_EVERNOTE_OAUTH_AUTHORIZE_URL',
        'https://www.evernote.com/oauth2/authorize'
    ),
    'oauth_token_url' => env(
        'CONNECTOR_EVERNOTE_OAUTH_TOKEN_URL',
        'https://www.evernote.com/oauth2/token'
    ),
    'oauth_revoke_url' => env(
        'CONNECTOR_EVERNOTE_OAUTH_REVOKE_URL',
        'https://www.evernote.com/oauth2/revoke'
    ),
    'api_base' => env(
        'CONNECTOR_EVERNOTE_API_BASE',
        'https://api.evernote.com'
    ),
];
