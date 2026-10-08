<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Shipping\BiteshipWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShippingWebhookController extends Controller
{
    public function __construct(
        protected BiteshipWebhookService $webhookService
    ) {}

    /**
     * Handle incoming Biteship shipment telemetry & delivery webhook.
     */
    public function handleBiteship(Request $request): JsonResponse
    {
        // Biteship confirms a webhook URL on installation with an empty, unsigned request and
        // only accepts it after an "ok" reply. A request with no data at all changes nothing,
        // so acknowledging it is safe; every request that carries data must still be signed.
        if ($request->all() === []) {
            return response()->json(['success' => true, 'message' => 'Biteship webhook endpoint is reachable'], 200);
        }

        if (! $this->webhookService->verifyWebhook($request)) {
            Log::warning('Biteship webhook signature verification failed', $this->webhookService->describeRejection($request));

            return response()->json(['message' => 'Invalid webhook signature'], 401);
        }

        $payload = $request->all();
        $updated = $this->webhookService->processWebhookPayload($payload);

        return response()->json([
            'success' => true,
            'message' => $updated ? 'Biteship webhook processed successfully' : 'Biteship webhook acknowledged without changes',
        ], 200);
    }
}
