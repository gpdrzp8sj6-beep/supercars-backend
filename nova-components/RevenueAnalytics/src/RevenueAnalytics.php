<?php

namespace Supercars\RevenueAnalytics;

use Laravel\Nova\Card;
use App\Models\Order;
use Carbon\Carbon;

class RevenueAnalytics extends Card
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
        return 'revenue-analytics';
    }

    /**
     * Prepare the card for JSON serialization.
     */
    public function jsonSerialize(): array
    {
        $data = [];

        // Calculate revenue for different periods
        $data[] = [
            'period' => 'Today',
            'revenue' => Order::whereDate('created_at', Carbon::today())->sum('total'),
            'label' => 'Today',
        ];

        $data[] = [
            'period' => 'This Week',
            'revenue' => Order::whereBetween('created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ])->sum('total'),
            'label' => 'This Week',
        ];

        $data[] = [
            'period' => 'This Month',
            'revenue' => Order::whereYear('created_at', Carbon::now()->year)
                            ->whereMonth('created_at', Carbon::now()->month)
                            ->sum('total'),
            'label' => 'This Month',
        ];

        $data[] = [
            'period' => 'This Year',
            'revenue' => Order::whereYear('created_at', Carbon::now()->year)->sum('total'),
            'label' => 'This Year',
        ];

        return array_merge(parent::jsonSerialize(), [
            'revenueData' => $data,
        ]);
    }
}
