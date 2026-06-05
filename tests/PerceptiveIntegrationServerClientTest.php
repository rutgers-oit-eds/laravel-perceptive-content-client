<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Rutgers\PerceptiveClient\Exceptions\PerceptiveContentIntegrationServerException;
use Rutgers\PerceptiveClient\Models\IntegrationServerCredential;
use Rutgers\PerceptiveClient\PerceptiveIntegrationServerClient;

beforeEach(function () {
    config()->set('perceptive-content-client.integration_server_url', 'https://is.example.com');
    config()->set('perceptive-content-client.default_department_id', 'dept_123');
});

// Builds a credential model without touching the database.
// setRawAttributes bypasses the encrypt mutator so the password is already in stored form.
function credential(): IntegrationServerCredential
{
    $credential = new IntegrationServerCredential;
    $credential->setRawAttributes([
        'username' => 'testuser',
        'password' => encrypt('testpassword'),
    ]);

    return $credential;
}

// Fakes the connection check as successful and any additional endpoints, then returns a ready client.
function makeClient(array $fakes = []): PerceptiveIntegrationServerClient
{
    Http::fake(array_merge(
        ['https://is.example.com/v2/connection' => Http::response('', 200)],
        $fakes
    ));

    return new PerceptiveIntegrationServerClient(credential());
}

// ─── Authentication ───────────────────────────────────────────────────────────

describe('authentication', function () {
    it('sends credentials when the connection check returns 401', function () {
        Http::fake([
            'https://is.example.com/v2/connection' => Http::sequence()
                ->push('', 401)
                ->push('', 200, ['X-IntegrationServer-Session-Hash' => 'new-hash-abc']),
        ]);

        new PerceptiveIntegrationServerClient(credential());

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/connection') &&
            $r->hasHeader('X-IntegrationServer-Username') &&
            $r->header('X-IntegrationServer-Username')[0] === 'testuser' &&
            $r->header('X-IntegrationServer-Password')[0] === 'testpassword'
        );
    });

    it('uses the session hash from the auth response in subsequent requests', function () {
        Http::fake([
            'https://is.example.com/v2/connection' => Http::sequence()
                ->push('', 401)
                ->push('', 200, ['X-IntegrationServer-Session-Hash' => 'new-hash-abc']),
            'https://is.example.com/v2/drawer' => Http::response(['drawers' => []], 200),
        ]);

        $client = new PerceptiveIntegrationServerClient(credential());
        $client->getDrawers();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/drawer') &&
            $r->header('X-IntegrationServer-Session-Hash')[0] === 'new-hash-abc'
        );
    });
});

// ─── Destroy ──────────────────────────────────────────────────────────────────

describe('destroy', function () {
    it('returns true on a successful disconnect', function () {
        $client = makeClient([
            'https://is.example.com/v1/connection' => Http::response('', 200),
        ]);

        expect($client->destroy())->toBeTrue();
    });

    it('returns false when disconnect does not return 200', function () {
        $client = makeClient([
            'https://is.example.com/v1/connection' => Http::response('', 204),
        ]);

        expect($client->destroy())->toBeFalse();
    });
});

// ─── Drawers ──────────────────────────────────────────────────────────────────

describe('drawers', function () {
    it('returns a collection of drawers', function () {
        $client = makeClient([
            'https://is.example.com/v2/drawer' => Http::response([
                'drawers' => [
                    ['id' => 'drawer_1', 'name' => 'Accounts Payable'],
                    ['id' => 'drawer_2', 'name' => 'HR Documents'],
                ],
            ]),
        ]);

        $drawers = $client->getDrawers();

        expect($drawers)->toHaveCount(2)
            ->and($drawers->first()->name)->toBe('Accounts Payable');
    });

    it('returns a single drawer by id', function () {
        $client = makeClient([
            'https://is.example.com/v2/drawer/drawer_1' => Http::response([
                'id' => 'drawer_1',
                'name' => 'Accounts Payable',
            ]),
        ]);

        $drawer = $client->getDrawer('drawer_1');

        expect($drawer->id)->toBe('drawer_1')
            ->and($drawer->name)->toBe('Accounts Payable');
    });
});

// ─── Document Types ───────────────────────────────────────────────────────────

describe('document types', function () {
    it('returns a collection of document types', function () {
        $client = makeClient([
            'https://is.example.com/v1/documentType' => Http::response([
                'documentTypes' => [
                    ['id' => 'dt_1', 'name' => 'Invoice'],
                    ['id' => 'dt_2', 'name' => 'Contract'],
                ],
            ]),
        ]);

        $types = $client->getDocumentTypes();

        expect($types)->toHaveCount(2)
            ->and($types->first()->name)->toBe('Invoice');
    });

    it('returns a single document type by id', function () {
        $client = makeClient([
            'https://is.example.com/v1/documentType/dt_1' => Http::response([
                'id' => 'dt_1',
                'name' => 'Invoice',
                'properties' => [],
            ]),
        ]);

        $type = $client->getDocumentType('dt_1');

        expect($type->id)->toBe('dt_1')
            ->and($type->name)->toBe('Invoice');
    });

    it('finds a document type by name', function () {
        $client = makeClient([
            'https://is.example.com/v1/documentType' => Http::response([
                'documentTypes' => [['id' => 'dt_1', 'name' => 'Invoice']],
            ]),
            'https://is.example.com/v1/documentType/dt_1' => Http::response([
                'id' => 'dt_1',
                'name' => 'Invoice',
                'properties' => [],
            ]),
        ]);

        expect($client->getDocumentTypeByName('Invoice')->id)->toBe('dt_1');
    });

    it('returns null when the document type name is not found', function () {
        $client = makeClient([
            'https://is.example.com/v1/documentType' => Http::response([
                'documentTypes' => [['id' => 'dt_1', 'name' => 'Invoice']],
            ]),
        ]);

        expect($client->getDocumentTypeByName('NonExistent'))->toBeNull();
    });
});

// ─── Documents ────────────────────────────────────────────────────────────────

describe('documents', function () {
    it('returns a document by id', function () {
        $client = makeClient([
            'https://is.example.com/v5/document/doc_123' => Http::response([
                'id' => 'doc_123',
                'name' => 'Invoice_2024_001',
            ]),
        ]);

        expect($client->getDocument('doc_123')->id)->toBe('doc_123');
    });

    it('creates a document and returns the id from the location header', function () {
        $client = makeClient([
            'https://is.example.com/v1/uniqueId*' => Http::response(['uniqueIds' => ['uid_new']]),
            'https://is.example.com/v1/documentType' => Http::response([
                'documentTypes' => [['id' => 'dt_1', 'name' => 'Invoice']],
            ]),
            'https://is.example.com/v1/documentType/dt_1' => Http::response([
                'id' => 'dt_1',
                'name' => 'Invoice',
                'properties' => [
                    ['id' => 'prop_1', 'name' => 'InvoiceNumber', 'type' => 'STRING'],
                ],
            ]),
            'https://is.example.com/v3/document' => Http::response('', 201, [
                'Location' => 'https://is.example.com/v3/document/doc_new_456',
            ]),
        ]);

        $id = $client->createDocument(
            ['documentType' => 'Invoice', 'field1' => 'val1'],
            ['InvoiceNumber' => 'INV-001'],
        );

        expect($id)->toBe('doc_new_456');
    });

    it('sends file content with the correct headers when adding a page', function () {
        Storage::fake();
        Storage::put('test/invoice.pdf', 'fake-pdf-content');

        $client = makeClient([
            'https://is.example.com/v1/document/doc_123/page' => Http::response('', 201),
        ]);

        $status = $client->addPageToDocument('doc_123', 'test/invoice.pdf');

        expect($status)->toBe(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/document/doc_123/page') &&
            $r->header('Content-Type')[0] === 'application/octet-stream' &&
            $r->header('X-IntegrationServer-Resource-Name')[0] === 'invoice.pdf'
        );
    });
});

// ─── Workflow ─────────────────────────────────────────────────────────────────

describe('workflow', function () {
    it('returns a collection of workflow queues', function () {
        $client = makeClient([
            'https://is.example.com/v1/workflowQueue' => Http::response([
                'workflowQueues' => [
                    ['id' => 'wq_1', 'name' => 'Approval Queue'],
                    ['id' => 'wq_2', 'name' => 'Exception Queue'],
                ],
            ]),
        ]);

        $queues = $client->getWorkflowQueues();

        expect($queues)->toHaveCount(2)
            ->and($queues->first()->name)->toBe('Approval Queue');
    });

    it('returns a single workflow queue by id', function () {
        $client = makeClient([
            'https://is.example.com/v1/workflowQueue/wq_1' => Http::response([
                'id' => 'wq_1',
                'name' => 'Approval Queue',
            ]),
        ]);

        expect($client->getWorkflowQueue('wq_1')->id)->toBe('wq_1');
    });

    it('returns a workflow item by id', function () {
        $client = makeClient([
            'https://is.example.com/v1/workflowItem/wi_123' => Http::response(['id' => 'wi_123']),
        ]);

        expect($client->getWorkflowItem('wi_123')->id)->toBe('wi_123');
    });

    it('posts to the correct queue when adding an item to workflow', function () {
        $client = makeClient([
            'https://is.example.com/v1/workflowQueue' => Http::response([
                'workflowQueues' => [['id' => 'wq_1', 'name' => 'Approval Queue']],
            ]),
            'https://is.example.com/v1/workflowQueue/wq_1' => Http::response([
                'id' => 'wq_1',
                'name' => 'Approval Queue',
            ]),
            'https://is.example.com/v1/workflowItem' => Http::response('', 201),
        ]);

        $client->addItemToWorkflow('doc_123', 'DOCUMENT', 'Approval Queue');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/workflowItem') &&
            $r->data()['objectId'] === 'doc_123' &&
            $r->data()['workflowQueueId'] === 'wq_1' &&
            $r->data()['itemPriority'] === 'MEDIUM'
        );
    });
});

// ─── Departments & Groups ─────────────────────────────────────────────────────

describe('departments and groups', function () {
    it('returns a collection of departments', function () {
        $client = makeClient([
            'https://is.example.com/v1/department' => Http::response([
                'departments' => [['id' => 'dept_123', 'name' => 'Accounting']],
            ]),
        ]);

        $departments = $client->getDepartments();

        expect($departments)->toHaveCount(1)
            ->and($departments->first()->name)->toBe('Accounting');
    });

    it('scopes user groups to the configured department id', function () {
        $client = makeClient([
            'https://is.example.com/v1/userGroup*' => Http::response([
                'userGroups' => [['id' => 'ug_1', 'name' => 'AP Clerks']],
            ]),
        ]);

        $groups = $client->getGroups();

        expect($groups)->toHaveCount(1);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'departmentId=dept_123'));
    });
});

// ─── Unique IDs ───────────────────────────────────────────────────────────────

describe('unique ids', function () {
    it('returns a collection of unique ids', function () {
        $client = makeClient([
            'https://is.example.com/v1/uniqueId*' => Http::response([
                'uniqueIds' => ['uid_abc', 'uid_def'],
            ]),
        ]);

        $ids = $client->getUniqueIds(2);

        expect($ids)->toHaveCount(2)
            ->and($ids->first())->toBe('uid_abc');
    });

    it('sends the requested quantity as a query parameter', function () {
        $client = makeClient([
            'https://is.example.com/v1/uniqueId*' => Http::response(['uniqueIds' => []]),
        ]);

        $client->getUniqueIds(5);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'quantity=5'));
    });
});

// ─── Error Handling ───────────────────────────────────────────────────────────

describe('error handling', function () {
    it('throws PerceptiveContentIntegrationServerException on a 403 response', function () {
        $client = makeClient([
            'https://is.example.com/v2/drawer' => Http::response('', 403),
        ]);

        expect(fn () => $client->getDrawers())
            ->toThrow(PerceptiveContentIntegrationServerException::class);
    });
});
