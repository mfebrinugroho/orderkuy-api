<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class XenditPaymentController extends Controller
{
    public function createPayment()
    {
        $response = Http::withBasicAuth(
            config('services.xendit.secret_key'),
            ''
        )
            ->withHeaders([
                'api-version' => '2024-11-11',
            ])
            ->post('https://api.xendit.co/v3/payment_requests', [
                'reference_id' => 'ORDER-' . time(),
                'type' => 'PAY',
                'country' => 'ID',
                'currency' => 'IDR',
                'request_amount' => 25000,
            ]);

        return $response->json();
    }

    public function test()
    {
        $response = Http::withBasicAuth(
            config('services.xendit.secret_key'),
            ''
        )
            ->withHeaders([
                'api-version' => '2024-11-11',
            ])
            ->post('https://api.xendit.co/v3/payment_requests', [
                'reference_id' => 'ORDER-' . time(),
                'type' => 'PAY',
                'country' => 'ID',
                'currency' => 'IDR',
                'request_amount' => 10000,
                'channel_code' => 'QRIS',
            ]);

        return response()->json([
            'status' => $response->status(),
            'data' => $response->json(),
        ]);
    }

    public function simulate(string $paymentRequestId)
    {
        $response = Http::withBasicAuth(
            config('services.xendit.secret_key'),
            ''
        )
            ->withHeaders([
                'api-version' => '2024-11-11',
            ])
            ->post(
                "https://api.xendit.co/v3/payment_requests/{$paymentRequestId}/simulate",
                [
                    'amount' => 10000,
                ]
            );

        return response()->json([
            'status' => $response->status(),
            'data' => $response->json(),
        ]);
    }
}
