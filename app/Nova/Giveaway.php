<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Markdown;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Panel;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Boolean;

class Giveaway extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Giveaway>
     */
    public static $model = \App\Models\Giveaway::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'title';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id', 'title',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        $fields = [
            ID::make()->sortable(),
            Text::make('Title')->sortable(),
            Markdown::make('Description')
                     ->displayUsing(fn () => Str::limit($this->description, 24)),
            BelongsToMany::make('Winning Orders', 'winningOrders', Order::class)
                ->fields(function () {
                    return [
                        Text::make('Numbers', function ($pivot) {
                            return implode(', ', json_decode($pivot->numbers ?? '[]'));
                        }),
                        Boolean::make('Is Winner', 'is_winner'),
                        Text::make('Winning Ticket', 'winning_ticket'),
                        Text::make('User', function ($pivot) {
                            // Access the order's user through the pivot's parent relation
                            $order = $pivot->order ?? $this->resource;
                            return $order->user ? $order->user->name : 'N/A';
                        }),
                    ];
                })
                ->readonly(),
            Currency::make('Alternative prize', 'alternative_prize'),
            Currency::make('Price per ticket', 'price'),
            Number::make('Total Tickets', 'ticketsTotal')->sortable(),
            Number::make('Tickets Sold', 'ticketsSold')->sortable()->readonly(),
            Number::make('Available Tickets', function () {
                $total = (int) ($this->ticketsTotal ?? 0);
                $sold = (int) ($this->ticketsSold ?? 0);
                return max(0, $total - $sold);
            })->sortable(),
            Text::make('Ticket Status', function () {
                $total = (int) ($this->ticketsTotal ?? 0);
                $sold = (int) ($this->ticketsSold ?? 0);
                $available = max(0, $total - $sold);
                $percentage = $total > 0 ? round(($sold / $total) * 100, 1) : 0;
                
                return sprintf(
                    '<div style="font-size: 13px;">
                        <div style="margin-bottom: 8px;">
                            <strong>Total:</strong> %s | <strong style="color: #10b981;">Taken:</strong> %s | <strong style="color: #3b82f6;">Available:</strong> %s
                        </div>
                        <div style="background: #e5e7eb; border-radius: 4px; height: 8px; overflow: hidden;">
                            <div style="background: #f59e0b; height: 100%%; width: %s%%;"></div>
                        </div>
                        <div style="margin-top: 4px; font-size: 11px; color: #6b7280;">%s%% sold</div>
                    </div>',
                    number_format($total),
                    number_format($sold),
                    number_format($available),
                    $percentage,
                    $percentage
                );
            })->asHtml()->onlyOnDetail(),
            Number::make('Tickets per User', 'ticketsPerUser'),
            Number::make('No. of winners', 'manyWinners'),
            Boolean::make('Auto draw', 'autoDraw'),
            Boolean::make('Total Tickets HIDDEN', 'ticketsTotalHidden'),
            DateTime::make('Will Draw On', 'closes_at'),

            Panel::make('Images', [
                Text::make('Giveaway Images', function () {
                    if (!is_array($this->images)) {
                        return '';
                    }

                    return collect($this->images)->map(function ($url) {
                        return '<img src="' . asset('storage/' . $url) . '" style="max-width: 80px; margin-right: 5px;">';
                    })->implode('');
                })->asHtml()->onlyOnDetail(),
                    ]),

        ];

        for ($i = 0; $i < 6; $i++) {  // for 5 images max
                $fields[] = Image::make("(optional) Image " . ($i + 1), "image_{$i}")
                    ->disk('public')
                    ->path('images')
                    ->prunable()
                    ->hideFromIndex()
                    ->hideFromDetail();
            }

        return $fields;
    }

    /**
     * Get the cards available for the resource.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(NovaRequest $request): array
    {
        // Only show cards on detail page, not on index
        if ($request->resourceId) {
            return [
                (new \App\Nova\Metrics\GiveawayRevenue())->width('1/4')->onlyOnDetail(),
                (new \App\Nova\Metrics\GiveawayOrders())->width('1/4')->onlyOnDetail(),
                (new \App\Nova\Metrics\GiveawayTicketsSold())->width('1/4')->onlyOnDetail(),
                (new \App\Nova\Metrics\GiveawayAverageOrderValue())->width('1/4')->onlyOnDetail(),
            ];
        }
        
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
