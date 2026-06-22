# Changelog

All notable changes to `padosoft/askmydocs-connector-evernote` are documented here.
This file follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] - 2026-06-22

### Changed

- Adopt `BaseConnector::resolveProjectKey()` from `padosoft/askmydocs-connector-base` v1.3 for project binding. The full and incremental sync paths now resolve the target project key via the shared base helper — `ConnectorInstallation::$project_key` first, then the host's `kb.ingest.default_project`, then the literal `'default'`. This replaces the previous `connector-<key>` (`connector-evernote`) fallback and enables multi-account / project-scoped installations to ingest into the correct project. Requires `padosoft/askmydocs-connector-base` `^1.3`.

## [1.0.0] - 2026-05-12

### Added

- Initial extraction from AskMyDocs v4.5/W4 inline connector framework.
- `EvernoteConnector` — OAuth2 authorize / callback / refresh, full + incremental sync of Evernote notes, soft-delete reconciliation, health probe via `/users/me`, disconnect with best-effort token revoke.
- `Support\EnmlToMarkdown` — ENML→markdown converter covering headings, paragraphs, lists, to-do checkboxes, blockquotes, fenced code blocks (with language hints), tables, inline formatting, and `en-media` / `en-crypt` skip markers.
- `Support\EnexImporter` — streaming `.enex` bulk importer (XMLReader-based, memory-bounded). Throws `InvalidEnexException` on malformed XML or non-`<en-export>` root.
- `EvernoteServiceProvider` — auto-registered via Laravel package discovery; merges per-package config under `connectors.providers.evernote`, exposes `connector-evernote-config` + `connector-evernote-assets` publish tags.
- Composer `extra.askmydocs.connectors` discovery — the base package's `ConnectorRegistry` picks up `EvernoteConnector` automatically.
- Test matrix — PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 on push and pull-request via GitHub Actions.
- Opt-in live test suite gated by `CONNECTOR_EVERNOTE_LIVE=1`.
