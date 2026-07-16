<?php

namespace App\Services\Google;

use RuntimeException;

class GoogleServiceAccountCredentials
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $clientEmail,
        public readonly string $privateKey,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        if (! empty($config['credentials_json'])) {
            $decoded = json_decode((string) $config['credentials_json'], true);

            if (! is_array($decoded)) {
                throw new RuntimeException('GOOGLE_CREDS_JSON is not valid JSON.');
            }

            return new self(
                projectId: (string) ($decoded['project_id'] ?? ''),
                clientEmail: (string) ($decoded['client_email'] ?? ''),
                privateKey: self::normalizePrivateKey((string) ($decoded['private_key'] ?? '')),
            );
        }

        $projectId = (string) ($config['project_id'] ?? '');
        $clientEmail = (string) ($config['client_email'] ?? '');
        $privateKey = self::normalizePrivateKey((string) ($config['private_key'] ?? ''));

        if ($projectId === '' || $clientEmail === '' || $privateKey === '') {
            throw new RuntimeException('Google service account credentials are not fully configured.');
        }

        return new self(
            projectId: $projectId,
            clientEmail: $clientEmail,
            privateKey: $privateKey,
        );
    }

    private static function normalizePrivateKey(string $value): string
    {
        return str_replace('\n', "\n", trim($value));
    }
}
