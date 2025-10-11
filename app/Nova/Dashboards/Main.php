<?php

namespace App\Nova\Dashboards;

use App\Nova\Metrics\OrdersToday;
use App\Nova\Metrics\OrdersThisMonth;
use App\Nova\Metrics\RegistrationsToday;
use App\Nova\Metrics\RegistrationsThisMonth;
use App\Nova\Metrics\TotalRevenue;
use App\Nova\Metrics\FailedOrders;
use App\Nova\Metrics\SuccessfulOrders;
use Laravel\Nova\Dashboards\Main as Dashboard;
use Supercars\RecentOrders\RecentOrders as RecentOrdersCard;
use Supercars\OrderAnalytics\OrderAnalytics;
use Supercars\RevenueAnalytics\RevenueAnalytics;

class Main extends Dashboard
{
    public function cards(): array
    {
        return [

            // 🔹 Row 1 — Quick Stats
            (new OrdersToday())->width('1/4'),
            (new OrdersThisMonth())->width('1/4'),
            (new RegistrationsToday())->width('1/4'),
            (new RegistrationsThisMonth())->width('1/4'),

            // 🔹 Row 2 — Revenue & Orders Overview
            (new TotalRevenue())->width('1/3'),
            (new SuccessfulOrders())->width('1/3'),
            (new FailedOrders())->width('1/3'),

            // 🔹 Row 3 — Charts
            (new RevenueAnalytics())->width('1/2'),
            (new OrderAnalytics())->width('1/2'),

            // 🔹 Row 4 — Recent Orders Table
            (new RecentOrdersCard())->width('full'),
        ];
    }
}
