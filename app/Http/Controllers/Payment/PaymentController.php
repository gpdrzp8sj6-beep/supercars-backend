<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PaymentController extends Controller
{


public function handle(Request $request)
{
    try {
        // Log the start of the process
        \Log::info('Starting OPPWA decryption process', [
            'headers' => $request->headers->all(),
            'request_size' => strlen($request->getContent())
        ]);

        $key_from_configuration = env('OPPWA_KEY');
        $iv_from_http_header = $request->header('X-Initialization-Vector');
        $auth_tag_from_http_header = $request->header('X-Authentication-Tag');
        $http_body = $request->getContent();

        // Log the raw inputs (without sensitive data if needed)
        \Log::debug('Decryption inputs received', [
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
        \Log::debug('Binary conversion results', [
            'key_length' => strlen($key),
            'iv_length' => strlen($iv),
            'auth_tag_length' => strlen($auth_tag),
            'cipher_text_length' => strlen($cipher_text)
        ]);

        $result = openssl_decrypt($cipher_text, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $auth_tag);

        if ($result === false) {
            $error = 'Decryption failed: ' . openssl_error_string();
            \Log::error($error);
            throw new \RuntimeException($error);
        }

        // Log successful decryption (be careful with sensitive data)
        \Log::info('Successfully decrypted payload', [
            'result_length' => strlen($result),
            'result_first_chars' => substr($result, 0, 10) // Don't log full sensitive data
        ]);

        return response()->json(['status' => 'success', 'data' => $result]);

    } catch (\Exception $e) {
        \Log::error('Decryption error: ' . $e->getMessage(), [
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'status' => 'error',
            'message' => 'Decryption failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

    private function getOrderStatus($resultCode)
    {
       return $resultCode === '000.000.000' ? 'completed' : 'failed';
    }

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

            $url = "https://eu-prod.oppwa.com/v1/checkouts";
            $data = "entityId=8ac9a4cd9662a1bc0196687d626128ad" .
                        "&amount=" . $amount .
                        "&currency=GBP" .
                        "&paymentType=DB" .
                        "&customer.email=" . $user->email .
                        "&customer.givenName=" . $user->forenames;

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
                return curl_error($ch);
            }
            curl_close($ch);
            $exp = [];
            $exp["status"] = true;
            $exp["checkoutId"] = $responseData["id"];

            $order->checkoutId = (int) $exp["checkoutId"]; // cast to int
            $order->save();

    	    return response()->json($exp);
    	} catch(Exception $err) {
    	    return response()->json(["status" => false]);
    	}
    }
}
