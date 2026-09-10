<?php

namespace App\Services\Shipping;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class BiteshipClient
{
    protected string $apiKey;

    protected string $baseUrl;

    protected int $originPostalCode;

    protected string $originAreaId;

    protected string $originAddress;

    protected string $originContactName;

    protected string $originContactPhone;

    protected int $timeout;

    protected string $webhookSecret;

    protected string $webhookSignatureKey;

    public function __construct()
    {
        $this->apiKey = (string) config('services.biteship.api_key', '');
        $this->baseUrl = rtrim((string) config('services.biteship.base_url', 'https://api.biteship.com'), '/');
        $this->originPostalCode = (int) config('services.biteship.origin_postal_code', 12220);
        $this->originAreaId = (string) config('services.biteship.origin_area_id', 'IDNP6IDNC417IDND2093IDNZ12220');
        $this->originAddress = (string) config('services.biteship.origin_address', 'Jl. Kebayoran Lama No. 12, Jakarta Selatan');
        $this->originContactName = (string) config('services.biteship.origin_contact_name', 'NOBYDERM Store');
        $this->originContactPhone = (string) config('services.biteship.origin_contact_phone', '081234567890');
        $this->timeout = (int) config('services.biteship.timeout', 10);
        $this->webhookSecret = (string) config('services.biteship.webhook_secret', '');
        $this->webhookSignatureKey = (string) config('services.biteship.webhook_signature_key', '');
    }

    /**
     * Create preconfigured HTTP client for Biteship API.
     */
    public function httpClient(): PendingRequest
    {
        $client = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Accept' => 'application/json',
        ])->timeout($this->timeout);

        if (app()->environment('local', 'testing')) {
            $client->withoutVerifying();
        }

        return $client;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getOriginPostalCode(): int
    {
        return $this->originPostalCode;
    }

    public function getOriginAreaId(): string
    {
        return $this->originAreaId;
    }

    public function getOriginAddress(): string
    {
        return $this->originAddress;
    }

    public function getOriginContactName(): string
    {
        return $this->originContactName;
    }

    public function getOriginContactPhone(): string
    {
        return $this->originContactPhone;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getWebhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function getWebhookSignatureKey(): string
    {
        return $this->webhookSignatureKey;
    }
}
