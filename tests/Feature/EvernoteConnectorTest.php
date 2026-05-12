<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorEvernote\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorEvernote\EvernoteConnector;
use Padosoft\AskMyDocsConnectorEvernote\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorEvernote\Tests\TestCase;

/**
 * Feature tests for {@see EvernoteConnector}.
 *
 * Every API interaction is stubbed via `Http::fake()`; host pipeline
 * dispatches go through a spy implementation of
 * {@see ConnectorIngestionContract}.
 */
final class EvernoteConnectorTest extends TestCase
{
    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
        Storage::fake('local');

        config()->set('connectors.providers.evernote.client_id', 'cid');
        config()->set('connectors.providers.evernote.client_secret', 'csec');
        config()->set('connectors.providers.evernote.redirect_uri', 'http://localhost/cb');
        config()->set('connectors.providers.evernote.api_base', 'https://api.evernote.com');
    }

    private function connector(): EvernoteConnector
    {
        return $this->app->make(EvernoteConnector::class);
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'evernote',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function seedActiveCredential(
        int $installationId,
        string $access = 'AT-evernote',
        ?string $refresh = 'RT-evernote',
        array $extra = [],
        string $tenantId = 'default',
    ): void {
        ConnectorCredential::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installationId,
            'encrypted_access_token' => Crypt::encryptString($access),
            'encrypted_refresh_token' => $refresh === null ? null : Crypt::encryptString($refresh),
            'expires_at' => Carbon::now()->addHour(),
            'extra_json' => $extra === [] ? null : $extra,
        ]);
    }

    private function initiateAndExtractState(int $installationId): string
    {
        Cache::flush();
        $url = $this->connector()->initiateOAuth($installationId);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['state'] ?? '');
    }

    public function test_initiate_oauth_returns_evernote_auth_url_with_state_token(): void
    {
        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://www.evernote.com/oauth2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_oauth_callback_exchanges_code_and_stores_tokens(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'evernote.com/oauth2/token' => Http::response([
                'access_token' => 'AT-new',
                'refresh_token' => 'RT-new',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'user_id' => 'user-42',
                'shard' => 's1',
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame('AT-new', $vault->getAccessToken($installation->id));
        $this->assertSame('RT-new', $vault->getRefreshToken($installation->id));
        $this->assertSame('user-42', $vault->getExtraKey($installation->id, 'evernote_user_id'));
        $this->assertSame('s1', $vault->getExtraKey($installation->id, 'evernote_shard'));

        $audits = array_column($this->spy->audits, 'eventType');
        $this->assertContains('installed', $audits);
    }

    public function test_oauth_callback_throws_on_invalid_state(): void
    {
        $installation = $this->makeInstallation();
        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => 'forged']);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_throws_on_missing_code(): void
    {
        $installation = $this->makeInstallation();
        $req = Request::create('/cb', 'GET', ['state' => 'whatever']);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_throws_on_token_exchange_failure(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'evernote.com/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'bad-code', 'state' => $state]);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_sync_full_walks_notes_search_and_dispatches_each_note_via_contract(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [
                    ['guid' => 'note-a', 'title' => 'Note A', 'updated' => 1620000000000],
                    ['guid' => 'note-b', 'title' => 'Note B', 'updated' => 1620000001000],
                ],
                'totalNotes' => 2,
            ], 200),
            'api.evernote.com/v1/notes/note-a*' => Http::response([
                'guid' => 'note-a',
                'title' => 'Note A',
                'content' => '<?xml version="1.0" encoding="UTF-8"?><en-note><p>body A</p></en-note>',
            ], 200),
            'api.evernote.com/v1/notes/note-b*' => Http::response([
                'guid' => 'note-b',
                'title' => 'Note B',
                'content' => '<?xml version="1.0" encoding="UTF-8"?><en-note><p>body B</p></en-note>',
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame([], $result->errors);
        $this->assertSame(2, $result->documentsAdded);
        $this->assertSame(0, $result->documentsRemoved);
        $this->assertCount(2, $this->spy->dispatches);
    }

    public function test_sync_full_writes_markdown_with_title_heading(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [['guid' => 'note-x', 'title' => 'Hello World']],
                'totalNotes' => 1,
            ], 200),
            'api.evernote.com/v1/notes/note-x*' => Http::response([
                'guid' => 'note-x',
                'title' => 'Hello World',
                'content' => '<en-note><h1>Section</h1><p>some body text</p></en-note>',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk('local');
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = (string) $disk->get($files[0]);
        $this->assertStringContainsString('# Hello World', $contents);
        $this->assertStringContainsString('# Section', $contents);
        $this->assertStringContainsString('some body text', $contents);
    }

    public function test_sync_full_dispatches_note_guid_in_metadata(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [['guid' => 'note-meta', 'title' => 'M']],
                'totalNotes' => 1,
            ], 200),
            'api.evernote.com/v1/notes/note-meta*' => Http::response([
                'guid' => 'note-meta',
                'title' => 'M',
                'content' => '<en-note><p>body</p></en-note>',
                'notebookGuid' => 'nb-1',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $this->assertCount(1, $this->spy->dispatches);
        $dispatch = $this->spy->dispatches[0];
        $this->assertSame('evernote', $dispatch['metadata']['connector']);
        $this->assertSame('note-meta', $dispatch['metadata']['evernote_note_guid']);
        $this->assertSame('oauth', $dispatch['metadata']['evernote_source']);
        $this->assertSame('application/vnd.evernote.note+xml', $dispatch['mimeType']);
    }

    public function test_sync_incremental_processes_changed_notes_only(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        $since = Carbon::parse('2026-05-10T12:00:00Z');

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [
                    ['guid' => 'note-fresh', 'title' => 'Fresh', 'updated' => 1620000003000],
                ],
                'totalNotes' => 1,
            ], 200),
            'api.evernote.com/v1/notes/note-fresh*' => Http::response([
                'guid' => 'note-fresh',
                'title' => 'Fresh',
                'content' => '<en-note><p>fresh content</p></en-note>',
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(1, $result->documentsUpdated);
        $this->assertCount(1, $this->spy->dispatches);
    }

    public function test_sync_incremental_deleted_notes_route_through_soft_delete(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);
        $this->spy->remoteIdsThatMatch['note-bye'] = 'default';

        $since = Carbon::parse('2026-05-10T12:00:00Z');

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [
                    [
                        'guid' => 'note-bye',
                        'title' => 'Removed',
                        'deleted' => 1620000004000,
                    ],
                ],
                'totalNotes' => 1,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(1, $result->documentsRemoved);
        $this->assertCount(1, $this->spy->deletions);
        $this->assertSame('evernote_note_guid', $this->spy->deletions[0]['metadata_key']);
        $this->assertSame('note-bye', $this->spy->deletions[0]['remote_id']);
    }

    public function test_sync_incremental_uses_utc_zulu_format_in_search_filter(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [],
                'totalNotes' => 0,
            ], 200),
        ]);

        $since = Carbon::parse('2026-05-10T13:45:30+02:00'); // -> 11:45:30 UTC
        $this->connector()->syncIncremental($installation->id, $since);

        Http::assertSent(function ($req) {
            $body = (string) $req->body();
            $decoded = json_decode($body, true);
            if (! is_array($decoded)) {
                return false;
            }
            $words = $decoded['filter']['words'] ?? '';

            return $words === 'updated:20260510T114530Z';
        });
    }

    public function test_sync_incremental_falls_back_to_full_when_no_watermark(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [],
                'totalNotes' => 0,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
    }

    public function test_disconnect_calls_provider_revoke_and_clears_credentials(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake();

        $this->connector()->disconnect($installation->id);

        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/oauth2/revoke'));
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_health_returns_healthy_when_users_me_succeeds(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/users/me' => Http::response(['user' => ['id' => 1]], 200),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $status->state);
    }

    public function test_health_returns_errored_without_credentials(): void
    {
        $installation = $this->makeInstallation();
        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_health_returns_errored_on_401(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/users/me' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_pii_redaction_applied_via_ioc_contract(): void
    {
        $this->spy->redactionPrefix = '[REDACTED] ';

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [['guid' => 'note-pii']],
                'totalNotes' => 1,
            ], 200),
            'api.evernote.com/v1/notes/note-pii*' => Http::response([
                'guid' => 'note-pii',
                'title' => 'Sensitive',
                'content' => '<en-note><p>mail me at user@example.com today</p></en-note>',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk('local');
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = (string) $disk->get($files[0]);
        $this->assertStringContainsString('[REDACTED]', $contents);
    }
}
