<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleAccessTokenProvider
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SHEETS_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    public function __construct(
        private readonly GoogleServiceAccountCredentials $credentials,
    ) {
    }

    public function getAccessToken(): string
    {
        $cacheKey = 'google_sheets_access_token:'.md5($this->credentials->clientEmail);

        return Cache::remember($cacheKey, now()->addMinutes(50), function (): string {
            $response = Http::asForm()->post(self::TOKEN_ENDPOINT, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->buildJwtAssertion(),
            ])->throw()->json();

            $accessToken = (string) ($response['access_token'] ?? '');

            if ($accessToken === '') {
                throw new RuntimeException('Google OAuth token response did not contain an access token.');
            }

            return $accessToken;
        });
    }

    private function buildJwtAssertion(): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $issuedAt = time();
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $this->credentials->clientEmail,
            'scope' => self::SHEETS_SCOPE,
            'aud' => self::TOKEN_ENDPOINT,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], JSON_THROW_ON_ERROR));

        $unsignedToken = $header.'.'.$payload;
        $signature = '';

        $success = openssl_sign(
            $unsignedToken,
            $signature,
            $this->credentials->privateKey,
            OPENSSL_ALGO_SHA256,
        );

        if (! $success) {
            throw new RuntimeException('Failed to sign Google OAuth assertion.');
        }

        return $unsignedToken.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
