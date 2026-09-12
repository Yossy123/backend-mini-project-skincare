<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BiteshipWebhookController extends Controller
{
    public function handle(Request $request)
    {
        if (empty($request->all())) {
            return response()->json(['status' => 'ok'], 200);
        }

        $signature = $request->header('X-Biteship-Signature');
        $expectedSecret = env('BITESHIP_WEBHOOK_SECRET');

        if ($signature !== $expectedSecret) {
            Log::warning('Biteship webhook: invalid signature', ['received' => $signature]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->input('event');
        Log::info('Biteship webhook received', $request->all());

        switch ($event) {
            case 'order.status':
                break;
            case 'order.price':
                break;
            case 'order.waybill_id':
                break;
        }

        return response()->json(['status' => 'received'], 200);
    }
}
