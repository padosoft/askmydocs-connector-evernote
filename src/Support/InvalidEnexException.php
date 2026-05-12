<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorEvernote\Support;

/**
 * Raised by {@see EnexImporter::import()} when the uploaded `.enex`
 * file is missing, unreadable, malformed XML, or simply not an
 * Evernote export (root element is not `<en-export>`).
 *
 * Hosts catch this exception and map it to HTTP 422 + a structured
 * error payload — R14 forbids silently returning 200 on a parse
 * failure.
 */
class InvalidEnexException extends \RuntimeException {}
