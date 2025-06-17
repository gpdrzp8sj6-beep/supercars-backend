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
            Number::make('Total Tickets', 'ticketsTotal'),
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
