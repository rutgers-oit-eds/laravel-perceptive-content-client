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
        } catch (RequestException $e) {
            $response = $this->performRequest($url, 'GET', [
                'X-IntegrationServer-Username' => $credential->username,
                'X-IntegrationServer-Password' => decrypt($credential->password),
            ]);

            $this->integration_server_session_hash = $response->header('X-IntegrationServer-Session-Hash');

            Log::debug('Setting session hash cookie');
            Cookie::queue('pc_session_hash', $this->integration_server_session_hash, 60);
        }
    }

    public function destroy()
    {
        $url = $this->integration_server_url.'/v1/connection';

        $response = $this->performRequest($url, 'DELETE');

        if ($response->status() == 200) {
            Cookie::queue(Cookie::forget('pc_session_hash'));

            return true;
        } else {
            return false;
        }
    }

    /*
     * PerceptiveIntegrationServerClient should return objects for calls with single results
     * PerceptiveIntegrationServerClient should return collections of items for calls with multiple results
     *
     * If time, create custom objects for each call, use it to wrap dates in Carbon, clean up results, etc.
     */

    // Drawer Management

    /**
     * @return Collection
     */
    public function getDrawers()
    {
        $url = $this->integration_server_url.'/v2/drawer';

        return collect($this->performRequest($url)->object()->drawers);
    }

    /**
     * @return mixed
     */
    public function getDrawer($id)
    {
        $url = $this->integration_server_url.'/v2/drawer/'.$id;

        return $this->performRequest($url)->object();
    }

    // Document Type Management

    /**
     * @return Collection
     */
    public function getDocumentTypes()
    {
        $url = $this->integration_server_url.'/v1/documentType';

        return collect($this->performRequest($url)->object()->documentTypes);
    }

    /**
     * @return mixed
     */
    public function getDocumentType($id)
    {
        $url = $this->integration_server_url.'/v1/documentType/'.$id;

        return $this->performRequest($url)->object();
    }

    public function getDocumentTypeByName($name)
    {
        $docType = $this->getDocumentTypes()->keyBy('name')->get($name);

        if (is_null($docType)) {
            return null;
        }

        return $this->getDocumentType($docType->id);
    }

    // Document Management

    /**
     * @return mixed
     */
    public function getDocument($id)
    {
        $url = $this->integration_server_url.'/v5/document/'.$id;

        return $this->performRequest($url)->object();
    }

    public function createDocument($keys, $properties)
    {
        $url = $this->integration_server_url.'/v3/document';

        // TODO: Wrap some kind of transaction around this to avoid job retry creating duplicate documents?
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-IntegrationServer-Session-Hash' => $this->integration_server_session_hash,
            ])
                ->connectTimeout(10)
                ->asJson()
                ->post($url, [
                    'info' => [
                        'keys' => $this->trimKeys($this->mergeUniqueField5Key($keys)),
                    ],
                    'properties' => $this->prepareCustomProperties($keys['documentType'], $properties),
                ]);
        } catch (RequestException $e) {
            throw new PerceptiveContentIntegrationServerException($e->getMessage(), $e->getCode());
        }

        if ($response->status() === 201) {
            $newDocumentUri = $response->header('Location');
            preg_match('/\/([\w_]+$)/', $newDocumentUri, $matches);

            return $matches[1];
        }
    }

    public function addPageToDocument($documentId, $filePath)
    {
        preg_match('/([\w.]+$)/', $filePath, $matches);
        $fileName = $matches[1];

        $url = $this->integration_server_url."/v1/document/$documentId/page";

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'X-IntegrationServer-Resource-Name' => $fileName,
            'X-IntegrationServer-Session-Hash' => $this->integration_server_session_hash,
        ])
            ->connectTimeout(10)
            ->withBody(Storage::get($filePath), 'application/octet-stream')
            ->post($url);

        return $response->status();
    }

    // Workflow Management

    public function getWorkflowQueues()
    {
        $url = $this->integration_server_url.'/v1/workflowQueue';

        return collect($this->performRequest($url)->object()->workflowQueues);
    }

    public function getWorkflowQueue($id)
    {
        $url = $this->integration_server_url.'/v1/workflowQueue/'.$id;

        return $this->performRequest($url)->object();
    }

    /**
     * @return mixed
     */
    public function getWorkflowItem($id)
    {
        $url = $this->integration_server_url.'/v1/workflowItem/'.$id;

        return $this->performRequest($url)->object();
    }

    public function addItemToWorkflow($itemId, $itemType, $destinationQueueName, $itemPriority = 'MEDIUM')
    {
        $url = $this->integration_server_url.'/v1/workflowItem';

        // TODO: Check to ensure WF queue exists before proceeding
        $workflowQueue = $this->getWorkflowQueueByName($destinationQueueName);

        Http::withHeaders([
            'Accept' => 'application/json',
            'X-IntegrationServer-Session-Hash' => $this->integration_server_session_hash,
        ])
            ->connectTimeout(10)
            ->asJson()
            ->post($url, [
                'objectId' => $itemId,
                'itemType' => $itemType,
                'workflowQueueId' => $workflowQueue->id,
                'itemPriority' => $itemPriority,
            ]);
    }

    // Department Management

    public function getDepartments()
    {
        $url = $this->integration_server_url.'/v1/department';

        return collect($this->performRequest($url)->object()->departments);
    }

    // User/Group Management

    public function getGroups()
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

    /**
     * @param  int  $count
     * @return Collection
     */
    public function getUniqueIds($count = 1)
    {
        $url = $this->integration_server_url.'/v1/uniqueId?quantity='.$count;

        return collect($this->performRequest($url)->object()->uniqueIds);
    }

    private function performRequest($url, $method = 'GET', $headers = [])
    {
        $headers = array_merge([
            'Accept' => 'application/json',
            'X-IntegrationServer-Session-Hash' => $this->integration_server_session_hash,
        ], $headers);

        try {
            return Http::withHeaders($headers)
                ->connectTimeout(10)
                ->send($method, $url)
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 403) {
                throw new PerceptiveContentIntegrationServerException('403 Forbidden', 403);
            }
            throw $e;
        }
    }

    private function getWorkflowQueueByName($queueName)
    {
        // TODO: Catch when a queue doesn't exist! Add error handling here, and above where this is called
        return $this->getWorkflowQueue(
            $this->getWorkflowQueues()
                ->keyBy('name')
                ->get($queueName)
                ->id
        );
    }

    private function trimKeys($keys)
    {
        return array_map(function ($item) {
            return substr($item, 0, 40);
        }, $keys);
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

    /**
     * Prepares a custom property value for submission to Perceptive Content Integration Server.
     * For dates, this involves parsing and converting the date to Unix timestamp in milliseconds.
     * For strings, we ensure we limit the string to the first 128 characters.
     * For all other values, no conversion is needed, so the value is returned as is.
     *
     * @return mixed
     */
    private function prepareCustomPropertyValue($propertyType, $propertyValue)
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
