<?php

namespace App\Services\Enrichment;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;

class HlidacStatuCompanyLookupClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.hlidac_statu.enabled', true)
            && filled((string) config('services.hlidac_statu.api_key'));
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws RequestException
     */
    public function findCompanyByExactName(string $companyName): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $response = $this->http->acceptJson()
            ->timeout(15)
            ->retry(2, 250)
            ->withHeaders([
                'Authorization' => 'Token '.config('services.hlidac_statu.api_key'),
            ])
            ->get(rtrim((string) config('services.hlidac_statu.base_url'), '/').'/FindCompanyId', [
                'companyName' => $companyName,
            ]);

        $response->throw();

        $payload = $response->json();

        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return $payload;
    }
}
