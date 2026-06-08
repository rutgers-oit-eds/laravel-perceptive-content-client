<?php

namespace Rutgers\PerceptiveClient;

use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Rutgers\PerceptiveClient\Exceptions\PerceptiveContentIntegrationServerException;
use Rutgers\PerceptiveClient\Models\IntegrationServerCredential;

class PerceptiveIntegrationServerClient
{
    private $integration_server_url;

    private $integration_server_session_hash;

    private $default_department_id;

    public function __construct(IntegrationServerCredential $credential)
    {
        $this->integration_server_url = config('perceptive-content-client.integration_server_url');
        $this->default_department_id = config('perceptive-content-client.default_department_id');
        $this->integration_server_session_hash = Cookie::get('pc_session_hash');

        $url = $this->integration_server_url.'/v2/connection';

        try {
            $this->performRequest($url);
        } catch (PerceptiveContentIntegrationServerException $e) {
            if ($e->getCode() !== 401) {
                throw $e;
            }

            $response = $this->performRequest($url, 'GET', [
                'X-IntegrationServer-Username' => $credential->username,
                'X-IntegrationServer-Password' => decrypt($credential->password),
            ]);

            $this->integration_server_session_hash = $response->header('X-IntegrationServer-Session-Hash');

            Log::debug('Setting session hash cookie');
            Cookie::queue('pc_session_hash', $this->integration_server_session_hash, 60);
        }
    }

    public function destroy(): bool
    {
        $url = $this->integration_server_url.'/v1/connection';

        $this->performRequest($url, 'DELETE');

        Cookie::queue(Cookie::forget('pc_session_hash'));

        return true;
    }

    // Drawer Management

    public function getDrawers(): Collection
    {
        $url = $this->integration_server_url.'/v2/drawer';

        return collect($this->performRequest($url)->object()->drawers);
    }

    public function getDrawer(string $id): object
    {
        $url = $this->integration_server_url.'/v2/drawer/'.$id;

        return $this->performRequest($url)->object();
    }

    // Document Type Management

    public function getDocumentTypes(): Collection
    {
        $url = $this->integration_server_url.'/v1/documentType';

        return collect($this->performRequest($url)->object()->documentTypes);
    }

    public function getDocumentType(string $id): object
    {
        $url = $this->integration_server_url.'/v1/documentType/'.$id;

        return $this->performRequest($url)->object();
    }

    public function getDocumentTypeByName(string $name): ?object
    {
        $docType = $this->getDocumentTypes()->keyBy('name')->get($name);

        if (is_null($docType)) {
            return null;
        }

        return $this->getDocumentType($docType->id);
    }

    // Document Management

    public function getDocument(string $id): object
    {
        $url = $this->integration_server_url.'/v5/document/'.$id;

        return $this->performRequest($url)->object();
    }

    public function createDocument(array $keys, array $properties): string
    {
        $url = $this->integration_server_url.'/v3/document';

        $response = $this->performRequest($url, 'POST', [], [
            'info' => [
                'keys' => $this->trimKeys($this->mergeUniqueField5Key($keys)),
            ],
            'properties' => $this->prepareCustomProperties($keys['documentType'], $properties),
        ]);

        if ($response->status() !== 201) {
            throw new PerceptiveContentIntegrationServerException(
                "Expected 201 from document creation but got {$response->status()}",
                $response->status()
            );
        }

        return basename(parse_url($response->header('Location'), PHP_URL_PATH));
    }

    public function addPageToDocument(string $documentId, string $filePath): void
    {
        preg_match('/([\w.]+$)/', $filePath, $matches);
        $fileName = $matches[1];

        $url = $this->integration_server_url."/v1/document/$documentId/page";

        try {
            Http::withHeaders([
                'Accept' => 'application/json',
                'X-IntegrationServer-Resource-Name' => $fileName,
                'X-IntegrationServer-Session-Hash' => $this->integration_server_session_hash,
            ])
                ->connectTimeout(10)
                ->withBody(Storage::get($filePath), 'application/octet-stream')
                ->post($url)
                ->throw();
        } catch (RequestException $e) {
            throw new PerceptiveContentIntegrationServerException(
                "HTTP {$e->response->status()}: {$e->getMessage()}",
                $e->response->status(),
                $e
            );
        }
    }

    // Workflow Management

    public function getWorkflowQueues(): Collection
    {
        $url = $this->integration_server_url.'/v1/workflowQueue';

        return collect($this->performRequest($url)->object()->workflowQueues);
    }

    public function getWorkflowQueue(string $id): object
    {
        $url = $this->integration_server_url.'/v1/workflowQueue/'.$id;

        return $this->performRequest($url)->object();
    }

    public function getWorkflowItem(string $id): object
    {
        $url = $this->integration_server_url.'/v1/workflowItem/'.$id;

        return $this->performRequest($url)->object();
    }

    public function addItemToWorkflow(string $itemId, string $itemType, string $destinationQueueName, string $itemPriority = 'MEDIUM'): void
    {
        $url = $this->integration_server_url.'/v1/workflowItem';

        $workflowQueue = $this->getWorkflowQueueByName($destinationQueueName);

        $this->performRequest($url, 'POST', [], [
            'objectId' => $itemId,
            'itemType' => $itemType,
            'workflowQueueId' => $workflowQueue->id,
            'itemPriority' => $itemPriority,
        ]);
    }

    // Department Management

    public function getDepartments(): Collection
    {
        $url = $this->integration_server_url.'/v1/department';

        return collect($this->performRequest($url)->object()->departments);
    }

    // User/Group Management

    public function getGroups(): Collection
    {
        $url = $this->integration_server_url.'/v1/userGroup?departmentId='.$this->default_department_id;

        return collect($this->performRequest($url)->object()->userGroups);
    }

    // Execute iScript
    public function executeiScript() // stub
    {
        //
    }

    // Helper Functions

    public function getUniqueIds(int $count = 1): Collection
    {
        $url = $this->integration_server_url.'/v1/uniqueId?quantity='.$count;

        return collect($this->performRequest($url)->object()->uniqueIds);
    }

    private function performRequest($url, $method = 'GET', $headers = [], $body = null)
    {
        $headers = array_merge([
            'Accept' => 'application/json',
            'X-IntegrationServer-Session-Hash' => $this->integration_server_session_hash,
        ], $headers);

        try {
            $request = Http::withHeaders($headers)->connectTimeout(10);

            if ($body !== null) {
                $request = $request->asJson();
            }

            $options = $body !== null ? ['json' => $body] : [];

            return $request->send($method, $url, $options)->throw();
        } catch (RequestException $e) {
            throw new PerceptiveContentIntegrationServerException(
                "HTTP {$e->response->status()}: {$e->getMessage()}",
                $e->response->status(),
                $e
            );
        }
    }

    private function getWorkflowQueueByName($queueName)
    {
        $queue = $this->getWorkflowQueues()->keyBy('name')->get($queueName);

        if ($queue === null) {
            throw new PerceptiveContentIntegrationServerException(
                "Workflow queue '$queueName' not found",
                404
            );
        }

        return $this->getWorkflowQueue($queue->id);
    }

    private function trimKeys($keys)
    {
        foreach (['field1', 'field2', 'field3', 'field4', 'field5'] as $field) {
            if (isset($keys[$field])) {
                $keys[$field] = substr($keys[$field], 0, 40);
            }
        }

        return $keys;
    }

    private function mergeUniqueField5Key($keys)
    {
        return array_merge($keys, [
            'field5' => $this->getUniqueIds()->first(),
        ]);
    }

    private function prepareCustomProperties($documentTypeName, $properties)
    {
        $properties = collect($properties);

        $documentType = $this->getDocumentTypeByName($documentTypeName);

        return collect($documentType->properties)
            ->keyBy('name')
            ->intersectByKeys($properties)
            ->map(function ($item, $key) use ($properties) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'value' => $this->prepareCustomPropertyValue($item->type, $properties->get($key)),
                    'childProperties' => null, // TODO: Not handling composite properties yet!
                ];
            })->values();
    }

    // Dates → Unix ms timestamp; strings → truncated to 128 chars; all others pass through.
    private function prepareCustomPropertyValue(string $propertyType, mixed $propertyValue): mixed
    {
        switch ($propertyType) {
            case 'DATE':
                return Carbon::parse($propertyValue)->getPreciseTimestamp(3);
            case 'STRING':
                return substr($propertyValue, 0, 128);
            default:
                return $propertyValue;
        }
    }
}
