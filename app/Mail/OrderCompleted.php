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
        $this->order = $order->fresh(['user']);
    }

    public function build()
    {
        return $this
            ->subject('Your order is complete')
            ->markdown('emails.order_completed')
            ->with([
                'order' => $this->order,
                'user' => $this->order->user,
            ]);
    }
}
