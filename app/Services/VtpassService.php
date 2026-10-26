<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class VtpassService
{
    public function environment(): string
    {
        $environment = (string) config('vtpass.environment');

        if (! in_array($environment, ['sandbox', 'live'], true)) {
            throw new RuntimeException(
                'VTPASS_ENVIRONMENT must be sandbox or live.'
            );
        }

        return $environment;
    }

    public function assertConfigured(): void
    {
        $environment = $this->environment();

        if (! config('vtpass.base_url')) {
            throw new RuntimeException(
                'The VTpass connection URL is not configured.'
            );
        }

        foreach (['api_key', 'public_key'] as $field) {
            $value = trim((string) config("vtpass.{$field}"));

            if ($value === '') {
                throw new RuntimeException(
                    "The VTpass {$environment} {$field} is missing."
                );
            }

            if (preg_match('/[^\x21-\x7E]/', $value)) {
                throw new RuntimeException(
                    "The VTpass {$field} contains unexpected characters."
                );
            }
        }
    }

    public function variations(string $network): array
    {
        $this->assertConfigured();

        $serviceId = config(
            "vtpass.networks.{$network}.service_id"
        );

        if (! is_string($serviceId) || $serviceId === '') {
            throw new RuntimeException('Unsupported network.');
        }

        $baseUrl = rtrim(
            (string) config('vtpass.base_url'),
            '/'
        );

        $response = Http::acceptJson()
            ->withHeaders([
                'api-key' => trim(
                    (string) config('vtpass.api_key')
                ),

                'public-key' => trim(
                    (string) config('vtpass.public_key')
                ),
            ])
            ->connectTimeout(
                (int) config('vtpass.connect_timeout', 10)
            )
            ->timeout(
                (int) config('vtpass.timeout', 30)
            )
            ->get("{$baseUrl}/service-variations", [
                'serviceID' => $serviceId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'VTpass returned HTTP '.$response->status().'.'
            );
        }

        $variations = $response->json('content.variations');

        if (! is_array($variations) || $variations === []) {
            throw new RuntimeException(
                'VTpass did not return a non-empty plan catalogue.'
            );
        }

        return $variations;
    }

    public function purchaseData(array $payload): array
    {
        return $this->post('pay', $payload);
    }

    public function requery(string $requestId): array
    {
        return $this->post('requery', [
            'request_id' => $requestId,
        ]);
    }

    private function post(string $endpoint, array $payload): array
    {
        $this->assertConfigured();

        $secretKey = trim(
            (string) config('vtpass.secret_key')
        );

        if ($secretKey === ''
            || preg_match('/[^\x21-\x7E]/', $secretKey)) {
            throw new RuntimeException(
                'Configure a valid VTpass secret key for this environment.'
            );
        }

        $baseUrl = rtrim(
            (string) config('vtpass.base_url'),
            '/'
        );

        // Intentionally no automatic retry for purchases.
        $response = Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'api-key' => trim(
                    (string) config('vtpass.api_key')
                ),
                'secret-key' => $secretKey,
            ])
            ->connectTimeout(
                (int) config('vtpass.connect_timeout', 10)
            )
            ->timeout(
                (int) config('vtpass.timeout', 30)
            )
            ->post("{$baseUrl}/{$endpoint}", $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'VTpass returned an unsuccessful HTTP response.'
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException(
                'VTpass returned an unreadable response.'
            );
        }

        return $data;
    }
}