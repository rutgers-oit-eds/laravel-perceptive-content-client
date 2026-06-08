# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer test                  # run full test suite
vendor/bin/pest --filter=drawers  # run a single describe block by name
vendor/bin/pest tests/PerceptiveIntegrationServerClientTest.php  # run one file
composer format                # run Laravel Pint (code style fixer)
```

## Architecture

This is a Laravel package (built on `spatie/laravel-package-tools`) that wraps the Perceptive Content Integration Server REST API. It is not a standalone app — it is installed into a host Laravel application via Composer.

### Key files

| File | Purpose |
|------|---------|
| `src/PerceptiveIntegrationServerClient.php` | The entire API client — all HTTP calls live here |
| `src/Models/IntegrationServerCredential.php` | Eloquent model; stores credentials in `pc_is_credentials`; password is auto-encrypted/decrypted via a mutator |
| `src/Exceptions/PerceptiveContentIntegrationServerException.php` | Single exception type thrown for all HTTP errors; `getCode()` returns the HTTP status |
| `src/PerceptiveClientServiceProvider.php` | Registers the config file; no bindings yet |
| `config/perceptive-content-client.php` | Reads `PERCEPTIVE_CONTENT_INTEGRATION_SERVER_URL` and `PERCEPTIVE_CONTENT_DEFAULT_DEPARTMENT_ID` from env |

### How the client works

`PerceptiveIntegrationServerClient` is instantiated with an `IntegrationServerCredential` model. The constructor calls `GET /v2/connection` to check for an existing session (cookie `pc_session_hash`). A 401 triggers re-authentication by replaying the same request with `X-IntegrationServer-Username` / `X-IntegrationServer-Password` headers; the returned `X-IntegrationServer-Session-Hash` header is stored in a cookie. Any other error code is re-thrown immediately without attempting auth.

All requests go through `performRequest()`, which merges the session hash header, wraps every HTTP error in `PerceptiveContentIntegrationServerException` (preserving the original as `$previous`), and supports an optional JSON body. The one exception is `addPageToDocument`, which sends a raw `application/octet-stream` body and therefore calls `Http::` directly but applies the same exception wrapping.

### Return type conventions

- Methods that return multiple results return a `Collection` of `object` (stdClass)
- Methods that return a single result return `object` (stdClass)
- `getDocumentTypeByName` returns `?object` (null when not found)
- Write methods (`addPageToDocument`, `addItemToWorkflow`) return `void` and throw on failure
- `createDocument` returns the new document ID as a `string`

### Document creation flow

`createDocument` performs several API calls internally: `GET /v1/uniqueId` (to populate `field5`), `GET /v1/documentType` + `GET /v1/documentType/{id}` (to resolve property IDs for the payload), then `POST /v3/document`. The `field1`–`field5` index values are truncated to 40 characters (system limit); `documentType` and `drawer` are reference lookups and must not be truncated.

### Integration Server API docs

The API reference lives at https://gitlab.rutgers.edu/imagenow/integration-server-docs/-/blob/main/docs/README.md (the README links to per-resource markdown files; the repo also contains the Python tooling used to generate them).

Key version notes: drawers use v2, document retrieval uses v5 (includes creation/modification user info), document creation uses v3, all workflow and department endpoints use v1.
