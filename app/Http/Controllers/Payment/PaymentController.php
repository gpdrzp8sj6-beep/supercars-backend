<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{


    public function handle(Request $request)
    {
        try {
            // Log the start of the process
            Log::info('Starting OPPWA webhook processing', [
                'headers' => $request->headers->all(),
                'request_size' => strlen($request->getContent())
            ]);

            $key_from_configuration = env('OPPWA_KEY');
            $iv_from_http_header = $request->header('X-Initialization-Vector');
            $auth_tag_from_http_header = $request->header('X-Authentication-Tag');
            $http_body = $request->getContent();

            // Log the raw inputs (without sensitive data if needed)
            Log::debug('Webhook decryption inputs received', [
                'iv_header_length' => strlen($iv_from_http_header),
                'auth_tag_header_length' => strlen($auth_tag_from_http_header),
                'body_length' => strlen($http_body),
                'key_configured' => $key_from_configuration ? 'yes' : 'no'
            ]);

            $key = hex2bin($key_from_configuration);
            $iv = hex2bin($iv_from_http_header);
            $auth_tag = hex2bin($auth_tag_from_http_header);
            $cipher_text = hex2bin($http_body);

            // Log the binary conversion
            Log::debug('Binary conversion results', [
                'key_length' => strlen($key),
                'iv_length' => strlen($iv),
                'auth_tag_length' => strlen($auth_tag),
                'cipher_text_length' => strlen($cipher_text)
            ]);

            $decryptedData = openssl_decrypt($cipher_text, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $auth_tag);

            if ($decryptedData === false) {
                $error = 'Decryption failed: ' . openssl_error_string();
                Log::error($error);
                throw new \RuntimeException($error);
            }

            // Parse the decrypted JSON data
            $webhookData = json_decode($decryptedData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Failed to parse webhook JSON: ' . json_last_error_msg();
                Log::error($error, ['decrypted_data' => $decryptedData]);
                throw new \RuntimeException($error);
            }

            Log::info('Successfully decrypted and parsed webhook payload', [
                'checkout_id' => $webhookData['ndc'] ?? 'unknown',
                'result_code' => $webhookData['result']['code'] ?? 'unknown',
                'result_description' => $webhookData['result']['description'] ?? 'unknown'
            ]);

            // Process the webhook data
            $this->processWebhookData($webhookData);

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Webhook processing error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Webhook processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getOrderStatus($resultCode)
    {
       return $resultCode === '000.000.000' ? 'completed' : 'failed';
    }

    /**
     * Generate a checkout session for payment processing
     * 
     * 3D Secure Testing:
     * - Set ENABLE_3DS_TEST_MODE=true in .env to enable test parameters
     * - Set 3DS_TEST_FLOW=challenge or 3DS_TEST_FLOW=frictionless to control flow
     * - These parameters allow testing 3DS with any test card
     */
    function generateCheckout(Request $request) {
        try {
            $request->validate([
                        'amount' => 'required|numeric',
                        'orderId' => 'required|numeric|exists:orders,id',
                    ]);

            $user = $request->user();
            $order = Order::where('id', $request->orderId)
                              ->where('user_id', $user->id)
                              ->first();

            if (!$order) {
                    return response()->json([
                        'message' => 'Order not found',
                    ], 403);
                }

            $amount = $request->amount;

            // For zero-amount orders, no checkout is required
            if ((float)$amount === 0.0 || (float)($order->total ?? 0) === 0.0) {
                return response()->json([
                    'status' => true,
                    'checkoutId' => null,
                    'message' => 'No checkout required for zero-amount order'
                ]);
            }

            $url = "https://eu-test.oppwa.com/v1/checkouts";
            $data = "entityId=8ac9a4cd9662a1bc0196687d626128ad" .
                        "&amount=" . $amount .
                        "&currency=GBP" .
                        "&paymentType=DB" .
                        "&customer.email=" . $user->email .
                        "&customer.givenName=" . $user->forenames;

            // Add 3D Secure test parameters if in test/webhook testing mode
            if (env('ENABLE_3DS_TEST_MODE', true)) {
                $data .= "&customParameters[3DS2_enrolled]=true";
                
                // Optional: specify flow type (challenge or frictionless)
                $flowType = env('3DS_TEST_FLOW', 'challenge'); // 'challenge' or 'frictionless'
                $data .= "&customParameters[3DS2_flow]={$flowType}";
                
                // Optional: force challenge flow with challengeIndicator
                if ($flowType === 'challenge') {
                    $data .= "&threeDSecure.challengeIndicator=04";
                }
                
                Log::info('3D Secure test parameters added to checkout', [
                    'order_id' => $order->id,
                    'flow_type' => $flowType
                ]);
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                           'Authorization: Bearer OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY='));
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);// this should be set to true in production
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $responseData = json_decode(curl_exec($ch), true);
            if(curl_errno($ch)) {
                Log::error('cURL error occurred while generating checkout', [
                    'order_id' => $order->id,
                    'error' => curl_error($ch)
                ]);
                return curl_error($ch);
            }
            curl_close($ch);
            $exp = [];
            $exp["status"] = true;
            $exp["checkoutId"] = $responseData["id"];

            $order->checkoutId = $exp["checkoutId"];
            $order->save();

    	    return response()->json($exp);
    	} catch(\Exception $err) {
    	    return response()->json(["status" => false]);
    	}
    }

    private function processWebhookData(array $webhookData)
    {
        // Handle nested payload structure from OPPWA
        $payload = $webhookData['payload'] ?? $webhookData;

        // Extract checkout ID and result code from webhook data
        $checkoutId = $payload['ndc'] ?? null;
        $resultCode = $payload['result']['code'] ?? null;

        if (!$checkoutId || !$resultCode) {
            Log::error('Invalid webhook data: missing checkoutId or result code', [
                'checkout_id' => $checkoutId,
                'result_code' => $resultCode,
                'webhook_data' => $webhookData
            ]);
            return;
        }

        // Find the order by checkoutId
        $order = Order::where('checkoutId', $checkoutId)->first();

        if (!$order) {
            Log::warning('Order not found for checkoutId', [
                'checkout_id' => $checkoutId,
                'result_code' => $resultCode
            ]);
            return;
        }

        // Determine order status based on result code
        $status = $this->determineOrderStatus($resultCode);

        Log::info('Processing webhook for order', [
            'order_id' => $order->id,
            'checkout_id' => $checkoutId,
            'result_code' => $resultCode,
            'determined_status' => $status
        ]);

        // Use database transaction to ensure atomicity
        DB::transaction(function () use ($order, $status) {
            // For completed orders, assign tickets before updating status
            if ($status === 'completed') {
                $this->assignTicketsForOrder($order);
                Log::info('Tickets assigned for order via webhook', ['order_id' => $order->id]);
            }

            // Update order status
            $order->update(['status' => $status]);
            Log::info('Order status updated via webhook', [
                'order_id' => $order->id,
                'new_status' => $status
            ]);
        });
    }

    private function determineOrderStatus(string $resultCode): string
    {
        // Based on OPPWA result codes
        if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[1][12]0)/', $resultCode)) {
            return 'completed';
        }

        if (preg_match('/^(000\.200|800\.400\.5|100\.400\.500)/', $resultCode)) {
            return 'pending';
        }

        if (preg_match('/^(000\.400\.0[^3]|000\.400\.100)/', $resultCode)) {
            return 'pending';
        }

        // Handle timeout/session expired errors - these should be treated as failed
        if (preg_match('/^(200\.300\.404)/', $resultCode)) {
            Log::warning("Payment session expired - marking order as failed", ['code' => $resultCode]);
            return 'failed';
        }

        return 'failed';
    }

    private function assignTicketsForOrder(Order $order): void
    {
        $cart = $order->cart;
        if (!$cart || !is_array($cart)) {
            Log::error("No cart data found for order {$order->id}");
            return;
        }

        $user = $order->user;
        $giveaways = \App\Models\Giveaway::whereIn('id', collect($cart)->pluck('id'))->get()->keyBy('id');

        $attachData = [];

        foreach ($cart as $item) {
            $giveawayId = $item['id'];
            $amount = $item['amount'];
            $requestedNumbers = $item['numbers'] ?? [];

            $giveaway = $giveaways->get($giveawayId);

            // Enforce per-order limit
            if ($amount > $giveaway->ticketsPerUser) {
                Log::error("Amount for giveaway ID {$giveawayId} exceeds ticketsPerUser limit for order {$order->id}");
                continue;
            }

            // Enforce cumulative per-user limit across previous completed orders
            $existingUserNumbers = DB::table('giveaway_order')
                ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
                ->where('orders.user_id', $user->id)
                ->where('orders.status', 'completed')
                ->where('giveaway_order.giveaway_id', $giveawayId)
                ->pluck('giveaway_order.numbers')
                ->filter()
                ->flatMap(function ($jsonNumbers) {
                    return json_decode($jsonNumbers, true) ?: [];
                });

            $existingCountForUser = $existingUserNumbers->count();
            if (($existingCountForUser + $amount) > $giveaway->ticketsPerUser) {
                Log::error("User already has {$existingCountForUser} ticket(s) for giveaway ID {$giveawayId}, order {$order->id} exceeds limit");
                continue;
            }

            // Get available numbers for this giveaway
            $availableNumbers = $this->getAvailableNumbers($giveaway, $amount, $requestedNumbers);

            if (count($availableNumbers) < $amount) {
                Log::error("Not enough available numbers for giveaway ID {$giveawayId}, order {$order->id}");
                continue;
            }

            $attachData[$giveawayId] = [
                'numbers' => json_encode($availableNumbers),
                'amount' => $amount
            ];
        }

        // Attach the giveaways to the order
        $order->giveaways()->attach($attachData);
    }

    private function getAvailableNumbers($giveaway, $amount, $requestedNumbers = [])
    {
        // Get all taken numbers for this giveaway
        $takenNumbers = DB::table('giveaway_order')
            ->where('giveaway_id', $giveaway->id)
            ->pluck('numbers')
            ->filter()
            ->flatMap(function ($jsonNumbers) {
                return json_decode($jsonNumbers, true) ?: [];
            })
            ->unique()
            ->values()
            ->toArray();

        $availableNumbers = [];

        // First, try to assign requested numbers if available
        if (!empty($requestedNumbers)) {
            foreach ($requestedNumbers as $number) {
                if (!in_array($number, $takenNumbers) && count($availableNumbers) < $amount) {
                    $availableNumbers[] = $number;
                }
            }
        }

        // Fill remaining slots with any available numbers
        $maxNumber = $giveaway->totalTickets;
        for ($i = 1; $i <= $maxNumber && count($availableNumbers) < $amount; $i++) {
            if (!in_array($i, $takenNumbers) && !in_array($i, $availableNumbers)) {
                $availableNumbers[] = $i;
            }
        }

        return $availableNumbers;
    }

    public function testWebhook(Request $request)
    {
        try {
            $request->validate([
                'checkoutId' => 'required|string',
                'resultCode' => 'required|string',
                'type' => 'required|string|in:success,failed,test'
            ]);

            $checkoutId = $request->checkoutId;
            $resultCode = $request->resultCode;

            // Find the order by checkoutId
            $order = Order::where('checkoutId', $checkoutId)->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found for checkoutId: ' . $checkoutId
                ], 404);
            }

            Log::info('Testing webhook processing', [
                'order_id' => $order->id,
                'checkout_id' => $checkoutId,
                'result_code' => $resultCode,
                'test_type' => $request->type
            ]);

            // Create sample webhook data based on the result code
            $webhookData = [
                "type" => "PAYMENT",
                "payload" => [
                    "id" => "test-" . time(),
                    "paymentType" => "PA",
                    "paymentBrand" => "VISA",
                    "amount" => $order->total . ".00",
                    "currency" => "GBP",
                    "presentationAmount" => $order->total . ".00",
                    "presentationCurrency" => "GBP",
                    "result" => [
                        "code" => $resultCode,
                        "description" => $resultCode === '000.000.000' ? 'Transaction succeeded' : 'Transaction failed'
                    ],
                    "ndc" => $checkoutId,
                    "timestamp" => now()->toISOString(),
                    "source" => "TEST_WEBHOOK"
                ]
            ];

            // Process the webhook data
            $this->processWebhookData($webhookData);

            return response()->json([
                'success' => true,
                'message' => 'Test webhook processed successfully',
                'orderId' => $order->id,
                'checkoutId' => $checkoutId,
                'resultCode' => $resultCode,
                'newStatus' => $order->fresh()->status
            ]);

        } catch (\Exception $e) {
            Log::error('Test webhook error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Test webhook failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
