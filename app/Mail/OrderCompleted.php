<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public Order $order;

    public function __construct(Order $order)
    {
        // Force fresh load with all necessary relationships and pivot data
        $this->order = $order->fresh(['user', 'giveaways' => function($query) {
            $query->withPivot(['numbers', 'amount']);
        }]);
    }

    public function build()
    {
        return $this
            ->subject('Your order is complete')
            ->view('emails.order_completed_simple')
            ->with([
                'order' => $this->order,
                'user' => $this->order->user,
            ]);
    }
}
