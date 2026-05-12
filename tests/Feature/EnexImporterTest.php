<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorEvernote\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorEvernote\Support\EnexImporter;
use Padosoft\AskMyDocsConnectorEvernote\Support\InvalidEnexException;
use Padosoft\AskMyDocsConnectorEvernote\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorEvernote\Tests\TestCase;

/**
 * Tests for the `.enex` bulk importer. Streams the XML and routes
 * every `<note>` through the IoC ingestion contract.
 */
final class EnexImporterTest extends TestCase
{
    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
        Storage::fake('local');
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'evernote',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);
    }

    private function importer(): EnexImporter
    {
        return $this->app->make(EnexImporter::class);
    }

    private function writeFixture(string $body): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'enex-'.uniqid('', true).'.enex';
        file_put_contents($path, $body);

        return $path;
    }

    public function test_imports_each_note_as_one_dispatch(): void
    {
        $installation = $this->makeInstallation();

        $enex = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<en-export>
  <note>
    <title>First</title>
    <content><![CDATA[<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE en-note SYSTEM "http://xml.evernote.com/pub/enml2.dtd"><en-note><p>body one</p></en-note>]]></content>
    <created>20260511T100000Z</created>
    <updated>20260511T110000Z</updated>
    <tag>alpha</tag>
    <tag>beta</tag>
  </note>
  <note>
    <title>Second</title>
    <content><![CDATA[<en-note><h1>H</h1><p>body two</p></en-note>]]></content>
    <created>20260511T100100Z</created>
    <updated>20260511T100200Z</updated>
  </note>
</en-export>
XML;

        $path = $this->writeFixture($enex);

        $result = $this->importer()->import($path, $installation, 'demo-project');

        $this->assertSame(2, $result->imported);
        $this->assertSame(0, $result->skipped);
        $this->assertSame([], $result->errors);
        $this->assertCount(2, $this->spy->dispatches);
        $this->assertSame('First', $this->spy->dispatches[0]['title']);
        $this->assertSame('Second', $this->spy->dispatches[1]['title']);
        $this->assertSame('enex', $this->spy->dispatches[0]['metadata']['evernote_source']);
        $this->assertSame(['alpha', 'beta'], $this->spy->dispatches[0]['metadata']['evernote_tags']);

        unlink($path);
    }

    public function test_empty_notes_skipped_not_imported(): void
    {
        $installation = $this->makeInstallation();

        $enex = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<en-export>
  <note>
    <title>Empty note</title>
    <content><![CDATA[<en-note></en-note>]]></content>
  </note>
</en-export>
XML;
        $path = $this->writeFixture($enex);

        $result = $this->importer()->import($path, $installation, 'demo-project');

        $this->assertSame(0, $result->imported);
        $this->assertSame(1, $result->skipped);
        $this->assertCount(0, $this->spy->dispatches);

        unlink($path);
    }

    public function test_rejects_missing_file(): void
    {
        $installation = $this->makeInstallation();

        $this->expectException(InvalidEnexException::class);
        $this->expectExceptionMessage('missing or unreadable');

        $this->importer()->import('/no/such/file.enex', $installation, 'demo-project');
    }

    public function test_rejects_non_evernote_xml_root(): void
    {
        $installation = $this->makeInstallation();

        $enex = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<other-root>
  <note><title>Hi</title></note>
</other-root>
XML;
        $path = $this->writeFixture($enex);

        try {
            $this->expectException(InvalidEnexException::class);
            $this->expectExceptionMessage('expected <en-export> root element');
            $this->importer()->import($path, $installation, 'demo-project');
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_truncated_or_malformed_xml(): void
    {
        $installation = $this->makeInstallation();
        $path = $this->writeFixture('not xml at all');

        try {
            $this->expectException(InvalidEnexException::class);
            $this->importer()->import($path, $installation, 'demo-project');
        } finally {
            @unlink($path);
        }
    }

    public function test_writes_markdown_body_with_title_heading(): void
    {
        $installation = $this->makeInstallation();

        $enex = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<en-export>
  <note>
    <title>My Title</title>
    <content><![CDATA[<en-note><p>my body</p></en-note>]]></content>
  </note>
</en-export>
XML;
        $path = $this->writeFixture($enex);

        $this->importer()->import($path, $installation, 'demo-project');

        $disk = Storage::disk('local');
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = (string) $disk->get($files[0]);
        $this->assertStringContainsString('# My Title', $contents);
        $this->assertStringContainsString('my body', $contents);

        @unlink($path);
    }

    public function test_pii_redaction_applied_via_ioc_contract(): void
    {
        $this->spy->redactionPrefix = '[X] ';
        $installation = $this->makeInstallation();

        $enex = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<en-export>
  <note>
    <title>Sensitive</title>
    <content><![CDATA[<en-note><p>contact me at user@example.com</p></en-note>]]></content>
  </note>
</en-export>
XML;
        $path = $this->writeFixture($enex);

        $this->importer()->import($path, $installation, 'demo-project');

        $disk = Storage::disk('local');
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = (string) $disk->get($files[0]);
        $this->assertStringContainsString('[X]', $contents);

        @unlink($path);
    }
}
