<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\OrderCompleted;

class ValidateCheckouts extends Command
{
    protected $signature = 'app:validate-checkout';
    protected $description = 'Validate pending checkouts and update their status';

    public function handle()
    {
        Order::where('status', 'pending')->each(function ($order) {
            // Auto-complete zero-amount orders and skip timeout logic
            if ((float)$order->total === 0.0) {
                if ($order->status !== 'completed') {
                    $order->update(['status' => 'completed']);
                    $this->info("Order {$order->id} marked as completed (zero-amount).");
                    // Send completion email for zero-amount orders
                    try {
                        Mail::to($order->user->email)->send(new OrderCompleted($order));
                        Log::info('Order completed email sent (zero-amount).', ['order_id' => $order->id]);
                    } catch (\Throwable $mailEx) {
                        Log::error('Failed to send order completed email: ' . $mailEx->getMessage(), [
                            'order_id' => $order->id,
                            'exception' => $mailEx,
                        ]);
                    }
                }
                return;
            }

            if ($order->created_at->lt(Carbon::now()->subMinutes(10))) {
                $order->update(['status' => 'failed']);
                $this->info("Order {$order->id} marked as failed (timeout).");
                return;
            }

            if (!$order->checkoutId) {
                $this->warn("Order {$order->id} has no checkoutId, skipping.");
                return;
            }

            $url = "https://eu-prod.oppwa.com/v1/checkouts/{$order->checkoutId}/payment?entityId=8ac9a4cd9662a1bc0196687d626128ad";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPHEADER => ['Authorization:Bearer OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY='],
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_RETURNTRANSFER => true,
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                $this->error("Curl error on order {$order->id}: " . curl_error($ch));
                curl_close($ch);
                return;
            }
            curl_close($ch);

            $data = json_decode($response, true);
            if (!isset($data['result']['code'])) {
                $this->error("Invalid response for order {$order->id}.");
                return;
            }

            $code = $data['result']['code'];
            $status = $this->determineStatus($code);
            $order->update(['status' => $status]);
            $this->info("Order {$order->id} updated to {$status}.");

            if ($status === 'completed') {
                try {
                    Mail::to($order->user->email)->send(new OrderCompleted($order));
                    Log::info('Order completed email sent.', ['order_id' => $order->id]);
                } catch (\Throwable $mailEx) {
                    Log::error('Failed to send order completed email: ' . $mailEx->getMessage(), [
                        'order_id' => $order->id,
                        'exception' => $mailEx,
                    ]);
                }
            }
        });
    }

    private function determineStatus(string $code): string
    {
        if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[1][12]0)/', $code)) {
            return 'completed';
        }

        if (preg_match('/^(000\.200|800\.400\.5|100\.400\.500)/', $code)) {
            return 'pending';
        }

        if (preg_match('/^(000\.400\.0[^3]|000\.400\.100)/', $code)) {
            return 'pending';
        }

        return 'failed';
    }
}
