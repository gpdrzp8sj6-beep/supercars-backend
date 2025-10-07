<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Mail\OrderCompleted;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class TestOrderEmail extends Command
{
    protected $signature = 'test:order-email {order_id} {--force}';
    protected $description = 'Test sending order completed email with ticket numbers for a specific order';

    public function handle()
    {
        $orderId = $this->argument('order_id');
        $force = $this->option('force');
        
        $order = Order::with(['giveaways', 'user'])->find($orderId);
        
        if (!$order) {
            $this->error("Order #{$orderId} not found.");
            return 1;
        }
        
        $this->info("=== ORDER #{$orderId} EMAIL TEST ===");
        $this->info("Status: {$order->status}");
        $this->info("User: {$order->user->email}");
        $this->info("Total: £" . number_format($order->total, 2));
        $this->info("Created: {$order->created_at}");
        $this->newLine();
        
        if ($order->status !== 'completed' && !$force) {
            $this->warn("Order status is '{$order->status}', not 'completed'.");
            $this->info("Use --force to send email anyway.");
            return 1;
        }
        
        $this->info("Giveaways and Ticket Numbers:");
        if ($order->giveaways->count() > 0) {
            foreach ($order->giveaways as $giveaway) {
                $numbers = json_decode($giveaway->pivot->numbers ?? '[]', true);
                $this->info("- {$giveaway->name}: " . implode(', ', $numbers));
            }
        } else {
            $this->warn("No giveaways/tickets found for this order!");
        }
        
        $this->newLine();
        
        if ($this->confirm('Send order completed email to ' . $order->user->email . '?')) {
            try {
                Mail::to($order->user->email)->send(new OrderCompleted($order));
                $this->info("✅ Email sent successfully!");
                
                Log::info('Manual order email test sent', [
                    'order_id' => $order->id,
                    'user_email' => $order->user->email,
                    'command_user' => 'artisan test:order-email'
                ]);
                
            } catch (\Throwable $ex) {
                $this->error("❌ Failed to send email: " . $ex->getMessage());
                return 1;
            }
        } else {
            $this->info("Email not sent.");
        }
        
        return 0;
    }
}