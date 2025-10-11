<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\HasOne;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Http\Requests\NovaRequest;

class Order extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Order>
     */
    public static $model = \App\Models\Order::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'id';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('User', 'user', \App\Nova\User::class),
            Text::make('Bought Giveaways', function () {
                return $this->giveaways->map(function ($giveaway) {
                    $pivot = $giveaway->pivot;
                    $tickets = implode(',', json_decode($pivot->numbers ?? '[]'));
                    $giveawayUrl = "/admin/resources/giveaways/{$giveaway->id}";
                    return "<a href='{$giveawayUrl}' class='link-default' target='_blank'>Giveaway ID: {$giveaway->id}</a> - Tickets: {$tickets}";
                })->implode('<br>');
            })->asHtml()->onlyOnDetail(),


            Select::make('Status')
                ->options(fn () => [
                    'completed' => 'Completed',
                    'pending' => 'Pending',
                    'cancelled' => 'Cancelled',
                    'failed' => 'Failed',
                ]),
            Currency::make("Total"),
            Currency::make("Credit Used", 'credit_used'),
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
            Text::make("Address Line 1", 'address_line_1')->hideFromIndex(),
            Text::make("Address Line 2", 'address_line_2')->hideFromIndex(),
            Text::make("City")->hideFromIndex(),
            Text::make("Postal Code", 'post_code')->hideFromIndex(),
            Text::make("Country")->hideFromIndex(),
            DateTime::make("Created At", 'created_at'),
            DateTime::make("Last Updated At", 'updated_at')->hideFromIndex(),
        ];
    }

    /**
     * Get the cards available for the resource.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, \Laravel\Nova\Lenses\Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array<int, \Laravel\Nova\Actions\Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }
}
