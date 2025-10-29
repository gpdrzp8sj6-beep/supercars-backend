<?php

namespace App\Nova\Lenses;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\HasOne;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;

class CreditPaidOrders extends Lens
{
    /**
     * Get the query builder / paginator for the lens.
     *
     * @param  \Laravel\Nova\Http\Requests\LensRequest  $request
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function query(LensRequest $request, $query)
    {
        return $request->withOrdering($request->withFilters(
            $query->where('credit_used', '>', 0)
                  ->with(['giveaways', 'user'])
                  ->orderBy('created_at', 'desc')
        ));
    }

    /**
     * Get the fields available to the lens.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('User', 'user', \App\Nova\User::class),
            Text::make('Bought Giveaways', function () {
                return $this->giveaways->map(function ($giveaway) {
                    $pivot = $giveaway->pivot;
                    $tickets = implode(',', json_decode($pivot->numbers ?? '[]'));
                    $ticketCount = count(json_decode($pivot->numbers ?? '[]', true) ?: []);
                    $giveawayUrl = "/admin/resources/giveaways/{$giveaway->id}";
                    return "<a href='{$giveawayUrl}' class='link-default' target='_blank'>Giveaway ID: {$giveaway->id}</a> - {$ticketCount} tickets: {$tickets}";
                })->implode('<br>');
            })->asHtml(),

            Text::make('Total Tickets', function () {
                return $this->giveaways->sum(function ($giveaway) {
                    return count(json_decode($giveaway->pivot->numbers ?? '[]', true) ?: []);
                });
            })->sortable(),

            Select::make('Status')
                ->options(fn () => [
                    'completed' => 'Completed',
                    'pending' => 'Pending',
                    'cancelled' => 'Cancelled',
                    'failed' => 'Failed',
                ]),
            Currency::make("Subtotal", 'original_total')->currency('GBP'),
            Currency::make("Amount Paid", 'total')->currency('GBP'),
            Currency::make("Credit Used", 'credit_used')->currency('GBP'),
            Text::make("Checkout ID", 'checkoutId')->hideFromIndex(),
            Badge::make('Payment Method', function () {
                if ($this->credit_used > 0) {
                    return $this->checkoutId ? 'Mixed (Credit + Payment)' : 'Credit Only';
                } elseif ($this->checkoutId) {
                    return 'Payment Gateway';
                }
                return 'Unknown';
            })->map([
                'Credit Only' => 'success',
                'Payment Gateway' => 'info',
                'Mixed (Credit + Payment)' => 'warning',
                'Unknown' => 'danger',
            ]),
            Text::make("Forenames")->hideFromIndex(),
            Text::make("Surname")->hideFromIndex(),
            Text::make("Phone"),
            DateTime::make("Created At", 'created_at'),
            DateTime::make("Last Updated At", 'updated_at')->hideFromIndex(),
        ];
    }

    /**
     * Get the cards available on the lens.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function cards(NovaRequest $request)
    {
        return [
            new \App\Nova\Metrics\CreditPaidOrdersCount,
            new \App\Nova\Metrics\CreditPaidTicketsCount,
            new \App\Nova\Metrics\CreditPaidRevenue,
        ];
    }

    /**
     * Get the filters available for the lens.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function filters(NovaRequest $request)
    {
        return [
            new \App\Nova\Filters\OrderGiveawayFilter,
        ];
    }

    /**
     * Get the actions available on the lens.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function actions(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the URI key for the lens.
     *
     * @return string
     */
    public function uriKey()
    {
        return 'credit-paid-orders';
    }
}