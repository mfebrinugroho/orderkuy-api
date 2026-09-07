<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class XenditWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Xendit webhook received', [
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
        ]);

        return response()->json([
            'message' => 'Webhook received',
        ]);
    }
}
