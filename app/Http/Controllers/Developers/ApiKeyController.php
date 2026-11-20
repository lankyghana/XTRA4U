<?php

namespace App\Http\Controllers\Developers;

use App\Http\Requests\Developers\StoreApiKeyRequest;
use App\Services\DeveloperApi\ApiKeyService;
use App\Services\DeveloperApi\DeveloperAuditLogger;
use App\Support\DeveloperApi\ApiException;
use Illuminate\Validation\ValidationException;

class ApiKeyController extends DeveloperController
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly DeveloperAuditLogger $audit,
    ) {}

    /**
     * Returns the "key created" page DIRECTLY (no redirect, no flash), so the raw key is never
     * written to the session store. It is shown once; only its hash exists afterwards.
     */
    public function store(StoreApiKeyRequest $request, int $application)
    {
        $app = $this->application($application);
        $data = $request->validated();

        try {
            $issued = $this->keys->create($app, $data['name'], $data['scopes'], now()->addDays((int) $data['expires_in_days']), null, $data['environment']);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['environment' => $e->getMessage()]);
        }

        $this->audit->log($this->actor(), 'api_key.created', $app->id, ['key_prefix' => $issued['key']->prefix, 'environment' => $data['environment']]);

        return $this->noStore(response()->view('developers.keys.created', [
            'app' => $app, 'key' => $issued['key'], 'plaintext' => $issued['plaintext'], 'verb' => 'created',
        ]));
    }

    public function rotate(int $application, int $key)
    {
        $app = $this->application($application);
        $old = $app->apiKeys()->whereKey($key)->firstOrFail(); // scoped to THIS application

        if ($old->isRevoked()) {
            throw ValidationException::withMessages(['key' => 'A revoked key cannot be rotated.']);
        }

        try {
            $issued = $this->keys->rotate($old);
        } catch (ApiException $e) {
            // A refused rotation never revokes anything (the whole operation is one transaction). Say so, whatever
            // the reason, so nobody assumes the key was retired or has to guess whether it still works.
            $message = $e->getMessage();
            if (! str_contains($message, 'remains active')) {
                $message .= ' The original key has not been changed and remains active; revoke it explicitly if you want to retire it.';
            }

            throw ValidationException::withMessages(['key' => $message]);
        }

        $this->audit->log($this->actor(), 'api_key.rotated', $app->id, ['key_prefix' => $issued['key']->prefix, 'replaced_prefix' => $old->prefix]);

        return $this->noStore(response()->view('developers.keys.created', [
            'app' => $app, 'key' => $issued['key'], 'plaintext' => $issued['plaintext'], 'verb' => 'rotated',
        ]));
    }

    public function revoke(int $application, int $key)
    {
        $app = $this->application($application);
        $k = $app->apiKeys()->whereKey($key)->firstOrFail();

        $this->keys->revoke($k);
        $this->audit->log($this->actor(), 'api_key.revoked', $app->id, ['key_prefix' => $k->prefix]);

        return redirect()->route('developers.applications.show', $app)->with('status', 'Key revoked. It stops working immediately.');
    }
}
