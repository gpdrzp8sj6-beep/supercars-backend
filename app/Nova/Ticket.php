<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Nova\Filters\GiveawayFilter;
use App\Nova\Filters\WinnerFilter;
use App\Nova\Filters\UserFilter;
use App\Nova\Filters\UserSearchFilter;
use App\Nova\Filters\OrderFilter;
use App\Nova\Actions\ExportTickets;
use Illuminate\Contracts\Database\Eloquent\Builder;

class Ticket extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Ticket>
     */
    public static $model = \App\Models\Ticket::class;

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
        'numbers',
        'winning_ticket',
    ];

    /**
     * Also search related models.
     * @var array
     */
    public static $searchRelations = [
        'giveaway' => ['title'],
        'order' => ['id'],
        'order.user' => ['name', 'email'],
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
            BelongsTo::make("Order"),
            BelongsTo::make("Giveaway"),
            Text::make('User', function ($pivot) {
                $order = \App\Models\Order::find($pivot->order_id);
                if ($order && $order->user) {
                    $userUrl = "/admin/resources/users/{$order->user->id}";
                    return "<a href='{$userUrl}' class='link-default' target='_blank'>{$order->user->fullName}</a>";
                }
                return 'N/A';
            })->asHtml(),
            Text::make("Numbers"),
            Boolean::make("Is Winner", "is_winner"),
            Number::make("Winning Ticket", "winning_ticket"),
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
        return [
            (new GiveawayFilter())->searchable(),
            new WinnerFilter(),
            (new UserFilter())->searchable(),
            (new OrderFilter())->searchable(),
        ];
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
        return [
            new ExportTickets(),
        ];
    }

    /**
     * Customize the index query to support searching related models.
     */
    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        $query = parent::indexQuery($request, $query);

        $search = trim((string) $request->get('search'));
        if ($search !== '') {
            $searchLower = strtolower($search);
            $query->where(function ($q) use ($search, $searchLower) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhereRaw('LOWER(numbers) LIKE ?', ["%{$searchLower}%"])
                  ->orWhere('winning_ticket', 'like', "%{$search}%")
                  ->orWhereHas('giveaway', function ($g) use ($searchLower) {
                      $g->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"]);
                  })
                  ->orWhereHas('order.user', function ($u) use ($searchLower) {
                      $u->whereRaw('LOWER(forenames) LIKE ?', ["%{$searchLower}%"])
                        ->orWhereRaw('LOWER(surname) LIKE ?', ["%{$searchLower}%"])
                        ->orWhereRaw("LOWER(CONCAT(forenames, ' ', surname)) LIKE ?", ["%{$searchLower}%"]) // full name
                        ->orWhereRaw('LOWER(email) LIKE ?', ["%{$searchLower}%"]);
                  });
            });
        }

        return $query;
    }
}
