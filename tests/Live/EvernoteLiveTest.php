<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorEvernote\Tests\Live;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorEvernote\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Live test — hits sandbox.evernote.com when `CONNECTOR_EVERNOTE_LIVE=1`
 * and a valid `CONNECTOR_EVERNOTE_TOKEN` is present in the environment.
 *
 * Operators run this manually to validate credentials. CI does NOT run
 * this suite by default (the gate env-var is unset on CI runners).
 *
 * See README.md §Credential setup → §Live testsuite for the step-by-
 * step setup.
 */
final class EvernoteLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONNECTOR_EVERNOTE_LIVE') !== '1') {
            $this->markTestSkipped('CONNECTOR_EVERNOTE_LIVE not set to 1 — live suite disabled.');
        }

        $token = getenv('CONNECTOR_EVERNOTE_TOKEN');
        if ($token === false || trim((string) $token) === '') {
            $this->markTestSkipped('Missing credential env var: CONNECTOR_EVERNOTE_TOKEN');
        }
    }

    #[Test]
    public function gets_authenticated_user_via_real_api(): void
    {
        // Evernote sandbox base. The /shard/s1/v2/users/me REST shim
        // returns the authenticated user profile — the lightest-weight
        // call we can make as a connectivity + auth smoke. Evernote's
        // production "list notebooks" surface is Thrift-only; the
        // sandbox does NOT expose a REST shim for it, so this test
        // intentionally targets users/me.
        $response = Http::withToken((string) getenv('CONNECTOR_EVERNOTE_TOKEN'))
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get('https://sandbox.evernote.com/shard/s1/v2/users/me');

        $this->assertTrue(
            $response->successful(),
            'Evernote /users/me returned: '.$response->status(),
        );
    }
}
