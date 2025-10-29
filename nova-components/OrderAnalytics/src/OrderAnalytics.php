<?php

namespace Supercars\OrderAnalytics;

use Laravel\Nova\Card;
use App\Models\Order;
use Carbon\Carbon;

class OrderAnalytics extends Card
{
    /**
     * The width of the card (1/3, 1/2, or full).
     *
     * @var string
     */
    public $width = '1/2';

    /**
     * Get the component name for the element.
     */
    public function component(): string
    {
        return 'order-analytics';
    }

    /**
     * Prepare the card for JSON serialization.
     */
    public function jsonSerialize(): array
    {
        $data = [];

        // Get order counts for the last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->toDateString();
            $count = Order::whereDate('created_at', $date)
                         ->where('status', 'completed')
                         ->count();

            $data[] = [
                'date' => $date,
                'day' => Carbon::parse($date)->format('D'), // Mon, Tue, etc.
                'count' => $count,
                'formatted_date' => Carbon::parse($date)->format('M j'),    
            ];
        }

        return array_merge(parent::jsonSerialize(), [
            'orderData' => $data,
        ]);
    }
}
