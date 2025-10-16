<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use App\Mail\OrderCompleted;

class PaymentController extends Controller
{


    public function handle(Request $request)
    {
        try {
            // Log the start of the process with OPPWA config
            $environment = config('oppwa.environment', 'test');
            $envKey = $environment === 'production' ? 'production' : 'test';
            
            Log::info('Starting OPPWA webhook processing with configuration', [
                'environment_config' => $environment,
                'env_key_used' => $envKey,
                'headers' => $request->headers->all(),
                'request_size' => strlen($request->getContent()),
                'timestamp' => now()->toISOString()
            ]);

            $key_from_configuration = $this->getWebhookKey();
            $iv_from_http_header = $request->header('X-Initialization-Vector');
            $auth_tag_from_http_header = $request->header('X-Authentication-Tag');
            $http_body = $request->getContent();

            // Validate required headers
            if (!$iv_from_http_header) {
                Log::error('Missing X-Initialization-Vector header');
                throw new \RuntimeException('Missing X-Initialization-Vector header');
            }
            
            if (!$auth_tag_from_http_header) {
                Log::error('Missing X-Authentication-Tag header');
                throw new \RuntimeException('Missing X-Authentication-Tag header');
            }
            
            if (!$key_from_configuration) {
                Log::error('Webhook key not configured for current environment');
                throw new \RuntimeException('Webhook key not configured');
            }

            // Log the raw inputs (without sensitive data if needed)
            Log::debug('Webhook decryption inputs received', [
                'iv_header_length' => strlen($iv_from_http_header),
                'auth_tag_header_length' => strlen($auth_tag_from_http_header),
                'body_length' => strlen($http_body),
                'key_configured' => $key_from_configuration ? 'yes' : 'no',
                'key_length_raw' => strlen($key_from_configuration),
                'key_preview' => $key_from_configuration ? substr($key_from_configuration, 0, 8) . '...' : 'NONE'
            ]);

            $key = hex2bin($key_from_configuration);
            $iv = hex2bin($iv_from_http_header);
            $auth_tag = hex2bin($auth_tag_from_http_header);
            $cipher_text = hex2bin($http_body);

            // Additional validation logging before decryption
            Log::info('Pre-decryption validation', [
                'key_valid' => strlen($key) === 32 ? 'YES' : 'NO (expected 32, got ' . strlen($key) . ')',
                'iv_valid' => strlen($iv) === 12 ? 'YES' : 'NO (expected 12, got ' . strlen($iv) . ')',
                'auth_tag_valid' => strlen($auth_tag) === 16 ? 'YES' : 'NO (expected 16, got ' . strlen($auth_tag) . ')',
                'cipher_text_valid' => strlen($cipher_text) > 0 ? 'YES' : 'NO',
                'headers_received' => [
                    'iv_header' => $iv_from_http_header ? 'SET' : 'MISSING',
                    'auth_tag_header' => $auth_tag_from_http_header ? 'SET' : 'MISSING'
                ]
            ]);

            // Try decryption with current environment key
            $decryptedData = openssl_decrypt($cipher_text, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $auth_tag);

            // If decryption fails, try with opposite environment key as fallback
            if ($decryptedData === false) {
                Log::warning('Primary key failed, trying fallback key');
                
                $currentEnv = config('oppwa.environment', 'production');
                $fallbackEnvKey = $currentEnv === 'production' ? 'test' : 'production';
                $fallbackKey = config("oppwa.{$fallbackEnvKey}.webhook_key");
                
                if ($fallbackKey && $fallbackKey !== $key_from_configuration) {
                    Log::info('Attempting decryption with fallback key', [
                        'current_env' => $currentEnv,
                        'fallback_env' => $fallbackEnvKey,
                        'fallback_key_set' => 'YES',
                        'fallback_key_preview' => substr($fallbackKey, 0, 8) . '...'
                    ]);
                    
                    $fallbackKeyBin = hex2bin($fallbackKey);
                    $decryptedData = openssl_decrypt($cipher_text, "aes-256-gcm", $fallbackKeyBin, OPENSSL_RAW_DATA, $iv, $auth_tag);
                    
                    if ($decryptedData !== false) {
                        Log::warning('Fallback key succeeded! Environment configuration may be incorrect', [
                            'configured_env' => $currentEnv,
                            'working_env' => $fallbackEnvKey
                        ]);
                    } else {
                        Log::error('Fallback key also failed');
                    }
                } else {
                    Log::info('No fallback key available or same as primary key');
                }
            }

            if ($decryptedData === false) {
                $opensslError = openssl_error_string();
                $error = 'Decryption failed: ' . ($opensslError ?: 'Unknown OpenSSL error');
                
                Log::error('Webhook decryption failure details', [
                    'openssl_error' => $opensslError,
                    'key_source' => 'config',
                    'environment' => config('oppwa.environment'),
                    'error_message' => $error,
                    'validation_summary' => [
                        'key_length_correct' => strlen($key) === 32,
                        'iv_length_correct' => strlen($iv) === 12,
                        'auth_tag_length_correct' => strlen($auth_tag) === 16,
                        'cipher_text_not_empty' => strlen($cipher_text) > 0
                    ],
                    'tried_fallback' => isset($fallbackKey) ? 'YES' : 'NO',
                    'debug_info' => [
                        'iv_hex' => $iv_from_http_header,
                        'auth_tag_hex' => $auth_tag_from_http_header,
                        'body_first_100_chars' => substr($http_body, 0, 100),
                        'both_keys_tried' => isset($fallbackKey) && $fallbackKey !== $key_from_configuration ? 'YES' : 'NO'
                    ]
                ]);
                
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

            // Validate that the requested amount matches the order total
            if ((float)$amount !== (float)$order->total) {
                Log::error('Amount mismatch in createCheckout', [
                    'order_id' => $order->id,
                    'request_amount' => $amount,
                    'order_total' => $order->total,
                ]);
                return response()->json([
                    'message' => 'Payment amount does not match order total',
                ], 400);
            }

            // For zero-amount orders, no checkout is required
            if ((float)$amount === 0.0 || (float)($order->total ?? 0) === 0.0) {
                return response()->json([
                    'status' => true,
                    'checkoutId' => null,
                    'message' => 'No checkout required for zero-amount order'
                ]);
            }

            // Get OPPWA configuration from config file
            $environment = config('oppwa.environment', 'test'); // 'test' or 'prod'
            $envKey = $environment === 'production' ? 'production' : 'test';
            
            $baseUrl = config("oppwa.{$envKey}.base_url");
            $entityId = config("oppwa.{$envKey}.entity_id");
            $bearerToken = config("oppwa.{$envKey}.bearer_token");
            
            // Log OPPWA configuration being used for checkout
            Log::info('OPPWA Configuration - createCheckout()', [
                'environment_config' => $environment,
                'env_key_used' => $envKey,
                'base_url' => $baseUrl,
                'entity_id' => $entityId,
                'bearer_token_configured' => $bearerToken ? 'YES' : 'NO',
                'bearer_token_length' => $bearerToken ? strlen($bearerToken) : 0,
                'bearer_token_preview' => $bearerToken ? substr($bearerToken, 0, 12) . '...' : 'NONE',
                'currency' => config('oppwa.payment.currency', 'GBP'),
                'payment_type' => config('oppwa.payment.payment_type', 'DB'),
                'amount' => $amount,
                'order_id' => $order->id,
                'merchant_transaction_id' => $order->id
            ]);
            
            $url = "{$baseUrl}/v1/checkouts";
            $data = "entityId={$entityId}" .
                        "&amount=" . $amount .
                        "&currency=" . config('oppwa.payment.currency', 'GBP') .
                        "&paymentType=" . config('oppwa.payment.payment_type', 'DB') .
                        "&merchantTransactionId=" . $order->id .
                        "&customer.email=" . $user->email .
                        "&customer.givenName=" . $user->forenames .
                        "&shopperResultUrl=" . env('FRONTEND_URL', 'http://localhost:3000') . "/payment/result?orderId=" . $order->id;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                           "Authorization: Bearer {$bearerToken}"));
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

            Log::info('Checkout created and saved', [
                'order_id' => $order->id,
                'checkout_id' => $exp["checkoutId"],
                'status_after_save' => $order->fresh()->status,
                'shopper_result_url' => route('payment.result')
            ]);

    	    return response()->json($exp);
    	} catch(\Exception $err) {
    	    return response()->json(["status" => false]);
    	}
    }

    /**
     * Handle the payment result update from frontend after OPPWA redirect
     * Marks order as pending and assigns tickets when payment processing begins
     */
    public function handlePaymentResult(Request $request) {
        try {
            $request->validate([
                'orderId' => 'required|numeric|exists:orders,id',
                'checkoutId' => 'required|string',
            ]);

            $orderId = $request->orderId;
            $checkoutId = $request->checkoutId;

            Log::info('handlePaymentResult called from frontend', [
                'order_id' => $orderId,
                'checkout_id' => $checkoutId,
                'request_params' => $request->all()
            ]);

            $order = Order::where('id', $orderId)
                          ->where('checkoutId', $checkoutId)
                          ->first();

            if (!$order) {
                Log::error('Order not found in payment result handler', [
                    'order_id' => $orderId,
                    'checkout_id' => $checkoutId
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            Log::info('Order found for payment result processing', [
                'order_id' => $order->id,
                'checkout_id' => $checkoutId,
                'current_status' => $order->status,
                'user_id' => $order->user_id
            ]);

            // Query OPPWA for actual payment status
            $paymentStatus = $this->getPaymentStatusFromOPPWA($checkoutId);

            if (!$paymentStatus) {
                Log::error('Failed to get payment status from OPPWA', [
                    'order_id' => $order->id,
                    'checkout_id' => $checkoutId
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to verify payment status'
                ], 500);
            }

            $resultCode = $paymentStatus['result']['code'];
            $newStatus = $this->determineOrderStatus($resultCode, $paymentStatus);

            Log::info('Payment status determined from OPPWA', [
                'order_id' => $order->id,
                'checkout_id' => $checkoutId,
                'oppwa_result_code' => $resultCode,
                'oppwa_result_description' => $paymentStatus['result']['description'] ?? 'unknown',
                'determined_status' => $newStatus,
                'current_order_status' => $order->status
            ]);

            // Only update if status would actually change
            if ($order->status !== $newStatus) {
                DB::transaction(function () use ($order, $newStatus, $checkoutId) {
                    $oldStatus = $order->status;
                    $order->status = $newStatus;
                    $order->save();

                    // Only assign tickets if payment is completed
                    if ($newStatus === 'completed') {
                        $this->assignTicketsForOrder($order);
                        Log::info('Tickets assigned for completed order', [
                            'order_id' => $order->id,
                            'checkout_id' => $checkoutId
                        ]);
                    }

                    Log::info('Order status changed via handlePaymentResult', [
                        'order_id' => $order->id,
                        'checkout_id' => $checkoutId,
                        'from_status' => $oldStatus,
                        'to_status' => $newStatus
                    ]);
                });
            } else {
                Log::info('Order status unchanged - no update needed', [
                    'order_id' => $order->id,
                    'current_status' => $order->status,
                    'determined_status' => $newStatus,
                    'checkout_id' => $checkoutId
                ]);
            }

            Log::info('handlePaymentResult completed successfully', [
                'order_id' => $order->id,
                'final_status' => $order->fresh()->status
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment result processed successfully',
                'order' => [
                    'id' => $order->id,
                    'status' => $order->fresh()->status
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Payment result handler exception', [
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'order_id' => $request->orderId ?? 'unknown',
                'checkout_id' => $request->checkoutId ?? 'unknown',
                'is_validation_exception' => $e instanceof \Illuminate\Validation\ValidationException,
                'validation_errors' => $e instanceof \Illuminate\Validation\ValidationException ? $e->errors() : null
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred processing your payment'
            ], 500);
        }
    }

    /**
     * Query OPPWA API to get the actual payment status for a checkout
     */
    private function getPaymentStatusFromOPPWA(string $checkoutId): ?array
    {
        try {
            // Get OPPWA configuration
            $environment = config('oppwa.environment', 'test');
            $envKey = $environment === 'production' ? 'production' : 'test';
            $baseUrl = config("oppwa.{$envKey}.base_url");
            $entityId = config("oppwa.{$envKey}.entity_id");
            $bearerToken = config("oppwa.{$envKey}.bearer_token");

            $url = "{$baseUrl}/v1/checkouts/{$checkoutId}/payment?entityId={$entityId}";

            Log::info('Querying OPPWA for payment status', [
                'checkout_id' => $checkoutId,
                'url' => $url,
                'environment' => $environment
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$bearerToken}"],
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30, // 30 second timeout
            ]);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                Log::error('cURL error querying OPPWA payment status', [
                    'checkout_id' => $checkoutId,
                    'error' => curl_error($ch)
                ]);
                curl_close($ch);
                return null;
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                Log::error('OPPWA API returned non-200 status', [
                    'checkout_id' => $checkoutId,
                    'http_code' => $httpCode,
                    'response' => $response
                ]);
                return null;
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to parse OPPWA payment status response', [
                    'checkout_id' => $checkoutId,
                    'response' => $response,
                    'json_error' => json_last_error_msg()
                ]);
                return null;
            }

            Log::info('Successfully retrieved payment status from OPPWA', [
                'checkout_id' => $checkoutId,
                'result_code' => $data['result']['code'] ?? 'unknown',
                'result_description' => $data['result']['description'] ?? 'unknown'
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('Exception querying OPPWA payment status', [
                'checkout_id' => $checkoutId,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return null;
        }
    }

    private function processWebhookData(array $webhookData)
    {
        Log::info('processWebhookData started', [
            'webhook_data_keys' => array_keys($webhookData),
            'timestamp' => now()->toISOString()
        ]);

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

        // Create unique webhook identifier to prevent duplicate processing
        $webhookId = $checkoutId; // Use only checkoutId for duplicate prevention
        $cacheKey = 'webhook_processed_' . $webhookId;

        // Check if this webhook was already processed recently (within last 5 minutes)
        if (Cache::has($cacheKey)) {
            Log::info('Duplicate webhook detected, skipping processing', [
                'checkout_id' => $checkoutId,
                'result_code' => $resultCode,
                'webhook_id' => $webhookId
            ]);
            return;
        }

        // Mark this webhook as processed
        Cache::put($cacheKey, true, 300); // 5 minutes

        // Find the order by checkoutId
        $order = Order::where('checkoutId', $checkoutId)->first();

        if (!$order) {
            Log::warning('Order not found for checkoutId in webhook', [
                'checkout_id' => $checkoutId,
                'result_code' => $resultCode,
                'webhook_id' => $webhookId
            ]);
            return;
        }

        Log::info('Webhook processing order found', [
            'order_id' => $order->id,
            'checkout_id' => $checkoutId,
            'current_status' => $order->status,
            'result_code' => $resultCode,
            'webhook_id' => $webhookId,
            'has_tickets' => $order->giveaways()->count() > 0
        ]);

        // Determine order status based on result code
        $newStatus = $this->determineOrderStatus($resultCode, $payload);
        $currentStatus = $order->status;

        // Validate status transition
        if (!$this->isValidStatusTransition($currentStatus, $newStatus)) {
            Log::info('Invalid status transition, skipping webhook processing', [
                'order_id' => $order->id,
                'checkout_id' => $checkoutId,
                'current_status' => $currentStatus,
                'new_status' => $newStatus,
                'result_code' => $resultCode
            ]);
            return;
        }

        Log::info('Processing webhook for order', [
            'order_id' => $order->id,
            'checkout_id' => $checkoutId,
            'result_code' => $resultCode,
            'result_description' => $payload['result']['description'] ?? 'unknown',
            'payment_type' => $payload['paymentType'] ?? 'unknown',
            'payment_brand' => $payload['paymentBrand'] ?? 'unknown',
            'amount' => $payload['amount'] ?? 'unknown',
            'currency' => $payload['currency'] ?? 'unknown',
            'current_status' => $currentStatus,
            'new_status' => $newStatus,
            'status_changed' => $currentStatus !== $newStatus,
            'webhook_payload_keys' => array_keys($payload)
        ]);

        // Use database transaction to ensure atomicity
        DB::transaction(function () use ($order, $newStatus, $currentStatus) {
            // Lock the order to prevent concurrent updates
            $lockedOrder = \App\Models\Order::where('id', $order->id)->lockForUpdate()->first();

            if (!$lockedOrder) {
                Log::warning("Order not found during webhook processing", ['order_id' => $order->id]);
                return;
            }

            // Check if status is already the target status to avoid redundant updates
            if ($lockedOrder->status === $newStatus) {
                Log::info("Order status already {$newStatus}, skipping update", ['order_id' => $order->id]);
                return;
            }

            // Mark that we're processing a webhook BEFORE any updates to prevent double email sending
            app()->singleton('webhook_processing', function () {
                return true;
            });
            
            // Handle ticket assignment/revocation based on status transition
            if ($newStatus === 'completed' && $currentStatus !== 'completed') {
                // Status changing TO completed - assign tickets
                $this->assignTicketsForOrder($lockedOrder);
                Log::info('Tickets assigned for order via webhook', ['order_id' => $order->id]);
            } elseif ($newStatus === 'failed' && $currentStatus === 'completed') {
                // Status changing FROM completed TO failed - revoke tickets
                $this->revokeTicketsForOrder($lockedOrder);
                Log::info('Tickets revoked for failed order via webhook', ['order_id' => $order->id]);
            }

            // Update order status
            $lockedOrder->update(['status' => $newStatus]);
            
            Log::info('Order status updated via webhook', [
                'order_id' => $order->id,
                'old_status' => $currentStatus,
                'new_status' => $newStatus,
            ]);
        }, 5);
        
        // After transaction is committed, refresh the order and log the giveaways
        if ($currentStatus !== $newStatus) {
            $order->refresh();
            $order->load(['giveaways' => function($query) {
                $query->withPivot(['numbers', 'amount']);
            }]);
            
            Log::info('Order refreshed after webhook transaction', [
                'order_id' => $order->id,
                'giveaways_count' => $order->giveaways->count(),
                'first_giveaway_numbers' => $order->giveaways->first()?->pivot?->numbers
            ]);
            
            // Send email manually after ensuring everything is properly set up
            if (in_array($newStatus, ['completed', 'failed'])) {
                try {
                    $email = $order->user?->email;
                    if ($email) {
                        // Log detailed giveaway information
                        $ticketInfo = [];
                        foreach ($order->giveaways as $giveaway) {
                            $numbers = json_decode($giveaway->pivot->numbers ?? '[]', true);
                            $ticketInfo[] = "Giveaway {$giveaway->id}: " . implode(', ', $numbers);
                        }
                        
                        Log::info('Sending payment confirmation email from webhook', [
                            'order_id' => $order->id,
                            'user_email' => $email,
                            'payment_status' => $newStatus,
                            'giveaways_count' => $order->giveaways->count(),
                            'ticket_numbers' => $ticketInfo,
                            'has_pivot_numbers' => $order->giveaways->first()?->pivot?->numbers ? 'yes' : 'no'
                        ]);
                        
                        Mail::to($email)->send(new OrderCompleted($order));
                        Log::info('Payment confirmation email sent successfully from webhook.', [
                            'order_id' => $order->id,
                            'status' => $newStatus
                        ]);
                    } else {
                        Log::warning('Payment status updated but user email missing.', [
                            'order_id' => $order->id,
                            'status' => $newStatus
                        ]);
                    }
                } catch (\Throwable $ex) {
                    Log::error('Failed to send payment confirmation email from webhook: ' . $ex->getMessage(), [
                        'order_id' => $order->id,
                        'status' => $newStatus,
                        'exception' => $ex,
                    ]);
                }
            }
        }
    }

    private function isValidStatusTransition(string $currentStatus, string $newStatus): bool
    {
        // Define valid status transitions
        $validTransitions = [
            'created' => ['pending'],
            'pending' => ['completed', 'failed'],
            'completed' => ['failed'], // Allow completed -> failed for chargebacks/refunds
            'failed' => [] // Failed is terminal, no transitions allowed
        ];

        return in_array($newStatus, $validTransitions[$currentStatus] ?? []);
    }

    private function revokeTicketsForOrder(Order $order): void
    {
        // Remove ticket assignments for this order
        $order->giveaways()->detach();
        
        Log::info('Tickets revoked for order', [
            'order_id' => $order->id,
            'giveaways_detached' => true
        ]);
    }

    private function determineOrderStatus(string $resultCode, array $payload = []): string
    {
        // Check for explicit hold indicators in the payload
        $riskScore = $payload['risk']['score'] ?? null;
        $threeDSecureStatus = $payload['threeDSecure']['eci'] ?? null;
        $paymentStatus = $payload['paymentStatus'] ?? null;

        // If payment status is explicitly set to 'HOLD' or similar
        if ($paymentStatus && in_array(strtoupper($paymentStatus), ['HOLD', 'PENDING', 'REVIEW'])) {
            Log::info("Payment explicitly marked as {$paymentStatus}", [
                'result_code' => $resultCode,
                'payment_status' => $paymentStatus
            ]);
            return 'pending';
        }

        // High risk score might indicate hold
        if ($riskScore && $riskScore > 80) {
            Log::info("Payment on hold due to high risk score", [
                'result_code' => $resultCode,
                'risk_score' => $riskScore
            ]);
            return 'pending';
        }

        // Success codes - payment completed successfully
        if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[1][12]0)/', $resultCode)) {
            Log::info("Payment successful", ['result_code' => $resultCode]);
            return 'completed';
        }

        // Pending codes - payment under review, on hold, or requires manual verification
        if (preg_match('/^(000\.200|800\.400\.5|100\.400\.500)/', $resultCode)) {
            Log::info("Payment pending - under review", ['result_code' => $resultCode]);
            return 'pending';
        }

        // Additional pending codes for manual verification/fraud checks
        if (preg_match('/^(000\.400\.0[^3]|000\.400\.100)/', $resultCode)) {
            Log::info("Payment pending - manual verification required", ['result_code' => $resultCode]);
            return 'pending';
        }

        // Payment hold codes - funds are held for verification
        if (preg_match('/^(800\.400\.1|800\.400\.2|800\.400\.3|800\.400\.4)/', $resultCode)) {
            Log::info("Payment on hold - verification needed", ['result_code' => $resultCode]);
            return 'pending';
        }

        // Awaiting final payment capture/settlement
        if (preg_match('/^(000\.400\.020|000\.400\.030)/', $resultCode)) {
            Log::info("Payment awaiting capture/settlement", ['result_code' => $resultCode]);
            return 'pending';
        }

        // Handle timeout/session expired errors - these should be treated as failed
        if (preg_match('/^(200\.300\.404)/', $resultCode)) {
            Log::warning("Payment session expired - marking order as failed", ['result_code' => $resultCode]);
            return 'failed';
        }

        // Default to failed for unrecognized codes
        Log::warning("Unknown payment result code - marking as failed", ['result_code' => $resultCode]);
        return 'failed';
    }

    private function assignTicketsForOrder(Order $order): void
    {
        $cart = $order->cart;
        if (!$cart || !is_array($cart)) {
            Log::error("No cart data found for order {$order->id}");
            return;
        }

        Log::info('Starting ticket assignment via webhook', [
            'order_id' => $order->id,
            'cart_data' => $cart,
            'cart_item_count' => count($cart)
        ]);

        $user = $order->user;
        $giveaways = \App\Models\Giveaway::whereIn('id', collect($cart)->pluck('id'))->get()->keyBy('id');

        Log::info('Ticket assignment details', [
            'order_id' => $order->id,
            'giveaways_loaded' => $giveaways->keys()->toArray(),
            'existing_giveaways' => $order->giveaways()->count()
        ]);

        $attachData = [];

        foreach ($cart as $item) {
            $giveawayId = $item['id'];
            $amount = $item['amount'];
            $requestedNumbers = $item['numbers'] ?? [];

            Log::info('Processing cart item in webhook assignment', [
                'order_id' => $order->id,
                'giveaway_id' => $giveawayId,
                'amount' => $amount,
                'requested_numbers_from_cart' => $requestedNumbers,
                'has_numbers_in_cart' => isset($item['numbers']) && !empty($item['numbers'])
            ]);

            $giveaway = $giveaways->get($giveawayId);

            if (!$giveaway) {
                Log::error("Giveaway not found for ID {$giveawayId} in order {$order->id}");
                continue;
            }

            // Enforce per-order limit
            if ($amount > $giveaway->ticketsPerUser) {
                Log::error("Amount for giveaway ID {$giveawayId} exceeds ticketsPerUser limit for order {$order->id}");
                continue;
            }

            // Check if this giveaway is already attached to this order
            $existingAttachment = $order->giveaways()->where('giveaway_id', $giveawayId)->first();
            if ($existingAttachment && !empty($existingAttachment->pivot->numbers)) {
                Log::info("Giveaway {$giveawayId} already has ticket numbers for order {$order->id}, skipping");
                continue;
            }

            // Enforce per-order limit
            if ($amount > $giveaway->ticketsPerUser) {
                Log::error("Amount for giveaway ID {$giveawayId} exceeds ticketsPerUser limit for order {$order->id}");
                continue;
            }

            // Enforce cumulative per-user limit across previous completed orders (exclude current order)
            $existingUserNumbers = DB::table('giveaway_order')
                ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
                ->where('orders.user_id', $user->id)
                ->where('orders.status', 'completed')
                ->where('orders.id', '!=', $order->id) // Exclude current order
                ->where('giveaway_order.giveaway_id', $giveawayId)
                ->orderBy('giveaway_order.id')
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

            Log::info('Numbers assigned via webhook', [
                'order_id' => $order->id,
                'giveaway_id' => $giveawayId,
                'requested_numbers' => $requestedNumbers,
                'available_numbers_assigned' => $availableNumbers,
                'amount_requested' => $amount,
                'amount_assigned' => count($availableNumbers),
                'assignment_successful' => count($availableNumbers) >= $amount
            ]);

            if (count($availableNumbers) < $amount) {
                Log::error("Not enough available numbers for giveaway ID {$giveawayId}, order {$order->id}");
                continue;
            }

            $attachData[$giveawayId] = [
                'numbers' => json_encode($availableNumbers),
                'amount' => $amount
            ];
        }

        // Sync the giveaways to the order (this will replace any existing attachments)
        try {
            $order->giveaways()->sync($attachData);
            Log::info('Tickets assigned for order', [
                'order_id' => $order->id,
                'attach_data' => $attachData,
                'total_giveaways' => count($attachData)
            ]);
        } catch (\Exception $e) {
            // Fallback: If amount column doesn't exist, try without amount
            if (str_contains($e->getMessage(), 'amount')) {
                Log::warning('Amount column error, retrying without amount field', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
                
                $fallbackData = [];
                foreach ($attachData as $giveawayId => $data) {
                    $fallbackData[$giveawayId] = [
                        'numbers' => $data['numbers']
                    ];
                }
                
                $order->giveaways()->sync($fallbackData);
                Log::info('Tickets assigned for order (without amount)', [
                    'order_id' => $order->id,
                    'attach_data' => $fallbackData,
                    'total_giveaways' => count($fallbackData)
                ]);
            } else {
                throw $e; // Re-throw if it's a different error
            }
        }
    }

    private function getAvailableNumbers($giveaway, $amount, $requestedNumbers = [])
    {
        // Get all taken numbers for this giveaway
        $takenNumbers = DB::table('giveaway_order')
            ->where('giveaway_id', $giveaway->id)
            ->orderBy('id')
            ->pluck('numbers')
            ->filter()
            ->flatMap(function ($jsonNumbers) {
                return json_decode($jsonNumbers, true) ?: [];
            })
            ->unique()
            ->values()
            ->toArray();

        Log::info('Checking available numbers for giveaway', [
            'giveaway_id' => $giveaway->id,
            'total_tickets' => $giveaway->ticketsTotal,
            'amount_requested' => $amount,
            'taken_numbers_count' => count($takenNumbers),
            'taken_numbers' => $takenNumbers,
            'requested_numbers' => $requestedNumbers
        ]);

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
        $maxNumber = $giveaway->ticketsTotal;
        $allAvailable = [];
        for ($i = 1; $i <= $maxNumber; $i++) {
            if (!in_array($i, $takenNumbers) && !in_array($i, $availableNumbers)) {
                $allAvailable[] = $i;
            }
        }
        // Randomly select the required amount from available numbers
        $remainingNeeded = $amount - count($availableNumbers);
        if ($remainingNeeded > 0 && count($allAvailable) > 0) {
            if ($remainingNeeded >= count($allAvailable)) {
                // Take all available
                $randomNumbers = $allAvailable;
            } else {
                // Randomly select remaining needed
                $randomKeys = array_rand($allAvailable, $remainingNeeded);
                if (is_array($randomKeys)) {
                    $randomNumbers = array_map(fn($key) => $allAvailable[$key], $randomKeys);
                } else {
                    $randomNumbers = [$allAvailable[$randomKeys]];
                }
            }
            $availableNumbers = array_merge($availableNumbers, $randomNumbers);
        }

        Log::info('Available numbers result', [
            'giveaway_id' => $giveaway->id,
            'available_numbers' => $availableNumbers,
            'available_count' => count($availableNumbers),
            'needed_count' => $amount,
            'success' => count($availableNumbers) >= $amount
        ]);

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

    /**
     * Get the webhook key for the current environment
     */
    private function getWebhookKey(): string
    {
        $environment = config('oppwa.environment', 'production');
        $envKey = $environment === 'production' ? 'production' : 'test';
        $webhookKey = config("oppwa.{$envKey}.webhook_key");
        
        // Log OPPWA configuration being used
        Log::info('OPPWA Configuration - getWebhookKey()', [
            'environment_config' => $environment,
            'env_key_used' => $envKey,
            'webhook_key_configured' => $webhookKey ? 'YES' : 'NO',
            'webhook_key_length' => $webhookKey ? strlen($webhookKey) : 0,
            'webhook_key_preview' => $webhookKey ? substr($webhookKey, 0, 8) . '...' : 'NONE'
        ]);
        
        return $webhookKey;
    }
}
