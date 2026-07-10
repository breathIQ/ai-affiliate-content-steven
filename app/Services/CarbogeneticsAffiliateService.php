<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the carbogenetics.com affiliate-provisioning endpoint
 * (POST /api/affiliate/provision on the new Next.js site).
 *
 * Given an email it finds-or-creates a carbogenetics affiliate and returns
 * their ref_code. Given a claimed code it verifies the code belongs to that
 * email before linking, so a signup can't attach someone else's affiliate ID.
 */
class CarbogeneticsAffiliateService
{
    private string $baseUrl;

    // Nullable so a missing secret degrades gracefully (signup keeps working
    // with provisioning disabled) instead of fataling the controller.
    private ?string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.carbogenetics.base_url') ?? 'https://carbogenetics.com', '/');
        $this->secret = config('services.carbogenetics.provision_secret');
    }

    public function isConfigured(): bool
    {
        return !blank($this->secret);
    }

    /**
     * Find-or-create the carbogenetics affiliate for this email.
     *
     * @return array{ok: bool, ref_code?: string, status?: string, created?: bool, error?: string}
     *         error is one of: unconfigured | code_not_found | email_mismatch | unreachable
     */
    public function provision(string $email, ?string $name = null, ?string $claimedCode = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'unconfigured'];
        }

        try {
            $response = Http::timeout(10)
                ->withToken($this->secret)
                ->acceptJson()
                ->post($this->baseUrl . '/api/affiliate/provision', array_filter([
                    'email' => $email,
                    'name' => $name,
                    'claimed_code' => $claimedCode,
                ]));
        } catch (\Throwable $e) {
            Log::warning('Carbogenetics affiliate provisioning unreachable', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'unreachable'];
        }

        if ($response->successful() && $response->json('ok')) {
            return [
                'ok' => true,
                'ref_code' => (string) $response->json('ref_code'),
                'status' => (string) $response->json('status'),
                'created' => (bool) $response->json('created'),
            ];
        }

        $error = $response->json('error');
        if (in_array($error, ['code_not_found', 'email_mismatch'], true)) {
            return ['ok' => false, 'error' => $error];
        }

        Log::warning('Carbogenetics affiliate provisioning failed', [
            'email' => $email,
            'http_status' => $response->status(),
            'body' => $response->body(),
        ]);
        return ['ok' => false, 'error' => 'unreachable'];
    }
}
