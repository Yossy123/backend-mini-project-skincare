<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payments) {}

    public function store(Request $request): JsonResponse
    {
        if (! $this->payments->isEnabled()) {
            return response()->json([
                'message' => 'Payment via Midtrans sementara tidak tersedia.',
            ], 503);
        }

        $data = $request->validate(['order_id' => ['required', 'integer', 'exists:orders,id']]);
        $order = Order::where('id', $data['order_id'])->where('user_id', $request->user()->id)->firstOrFail();

        try {
            $payment = $this->payments->createPayment($order);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Payment could not be prepared. Please try again.'], 502);
        }

        return response()->json([
            'data' => [
                'payment_id' => $payment->id,
                'token' => $payment->snap_token,
                'redirect_url' => $payment->redirect_url,
                'amount' => (float) $payment->amount,
            ],
        ], 201);
    }

    public function webhook(Request $request): JsonResponse
    {
        if (! $this->payments->isEnabled()) {
            return response()->json([
                'message' => 'Payment via Midtrans sementara tidak tersedia.',
            ], 503);
        }

        try {
            $this->payments->handleNotification($request->all());
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid notification.'], 401);
        }

        return response()->json(['status' => 'ok']);
    }
}
