<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use Carbon\Carbon;

class validate-checkout extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:validate-checkout';
    protected $description = 'Validate pending checkouts and update their status';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
       {
           $pendingOrders = Order::where('status', 'pending')->get();

           foreach ($pendingOrders as $order) {
               // if older than 10 minutes, mark as failed
               if ($order->created_at->lt(Carbon::now()->subMinutes(10))) {
                   $order->status = 'failed';
                   $order->save();
                   $this->info("Order {$order->id} marked as failed (timeout).");
                   continue;
               }

               // If no checkoutId, skip
               if (!$order->checkoutId) {
                   $this->warn("Order {$order->id} has no checkoutId, skipping.");
                   continue;
               }

               // Call payment API
               $url = "https://eu-prod.oppwa.com/v1/checkouts/{$order->checkoutId}/payment";
               $url .= "?entityId=8ac9a4cd9662a1bc0196687d626128ad";

               $ch = curl_init();
               curl_setopt($ch, CURLOPT_URL, $url);
               curl_setopt($ch, CURLOPT_HTTPHEADER, [
                   'Authorization:Bearer OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY='
               ]);
               curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
               curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
               curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

               $response = curl_exec($ch);
               if (curl_errno($ch)) {
                   $this->error("Curl error on order {$order->id}: " . curl_error($ch));
                   curl_close($ch);
                   continue;
               }
               curl_close($ch);

               $data = json_decode($response, true);

               if (isset($data['result']['code'])) {
                   $code = $data['result']['code'];

                   // Map result code to status
                   if (str_starts_with($code, '000.000')) {
                       $order->status = 'completed';
                   } elseif (str_starts_with($code, '000.100') || str_starts_with($code, '100.')) {
                       $order->status = 'failed';
                   } else {
                       // Keep it pending if status is not final
                       $this->info("Order {$order->id} still pending or unclear status ({$code}).");
                       continue;
                   }

                   $order->save();
                   $this->info("Order {$order->id} updated to {$order->status}.");
               } else {
                   $this->error("Invalid response for order {$order->id}.");
               }
           }
       }
   }
