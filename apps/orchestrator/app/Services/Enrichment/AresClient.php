<?php

namespace App\Services\Enrichment;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;

class AresClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * @return array<string, mixed>|null
     *
     * @throws RequestException
     */
    public function findSubjectByIco(string $ico): ?array
    {
        $response = $this->http->acceptJson()
            ->timeout(15)
            ->retry(2, 250)
            ->get(rtrim((string) config('services.ares.base_url'), '/').'/'.$ico);

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }
}
