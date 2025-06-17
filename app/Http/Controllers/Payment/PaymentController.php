<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PaymentController extends Controller
{


   public function handle(Request $request)
   {
        $key_from_configuration = env('OPPWA_KEY');
        $iv_from_http_header = $request->header('X-Initialization-Vector');
        $auth_tag_from_http_header = $request->header('X-Authentication-Tag');
        $http_body = $request->getContent();

        $key = hex2bin($key_from_configuration);
        $iv = hex2bin($iv_from_http_header);
        $auth_tag = hex2bin($auth_tag_from_http_header);
        $cipher_text = hex2bin($http_body);

        $result = openssl_decrypt($cipher_text, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $auth_tag);
         dd($result);

       return response()->json($result);
   }

    private function getOrderStatus($resultCode)
    {
       return $resultCode === '000.000.000' ? 'completed' : 'failed';
    }

    function generateCheckout(Request $request) {
        try {
            $request->validate([
                        'amount' => 'required|numeric',
                    ]);

            $user = $request->user();

            $amount = $request->amount;

            $url = "https://eu-test.oppwa.com/v1/checkouts";
            $data = "entityId=8a829417567d952801568d9d9e3c0b84" .
                        "&amount=" . $amount .
                        "&currency=GBP" .
                        "&paymentType=DB" .
                        "&integrity=true" .
                        "&customer.email=" . $user->email .
                        "&customer.givenName=" . $user->forenames;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                           'Authorization:Bearer OGE4Mjk0MTc1NjdkOTUyODAxNTY4ZDlkOWU5ZjBiODh8WHFkJWohS0NZVXpkPzRnWFJpbjM='));
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);// this should be set to true in production
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $responseData = json_decode(curl_exec($ch), true);
            if(curl_errno($ch)) {
                return curl_error($ch);
            }
            curl_close($ch);
            $exp = [];
            $exp["status"] = true;
            $exp["checkoutId"] = $responseData["id"];
            $exp["integrity"] = $responseData["integrity"];
    	    return response()->json($exp);
    	} catch(Exception $err) {
    	    return response()->json(["status" => false]);
    	}
    }
}
