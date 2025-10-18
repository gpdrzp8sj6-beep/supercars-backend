<?php

namespace App\Nova;

use Laravel\Nova\Fields\ID;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\Gravatar;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Auth\PasswordValidationRules;
use App\Nova\CreditTransaction;
use App\Nova\Order;
use App\Nova\Actions\AddCreditAction;

class User extends Resource
{
    use PasswordValidationRules;

    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\User>
     */
    public static $model = \App\Models\User::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'fullName';

    public static function icon(): string
    {
        return 'users'; // Can be any [Heroicon name](https://heroicons.com)
    }

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id', 'forenames', 'surname', 'phone', 'email',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field|\Laravel\Nova\Panel|\Laravel\Nova\ResourceTool|\Illuminate\Http\Resources\MergeValue>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Full Name', 'fullName')
                    ->showOnIndex()
                    ->hideWhenCreating()
                    ->hideWhenUpdating(),
            Text::make('Forenames')
                ->sortable()
                ->rules('required', 'max:255')
                ->hideFromIndex(),
            Text::make('Surname')
                    ->sortable()
                    ->rules('required', 'max:255')
                    ->hideFromIndex(),

            Text::make('Email')
                ->sortable()
                ->rules('required', 'email', 'max:254')
                ->creationRules('unique:users,email')
                ->updateRules('unique:users,email,{{resourceId}}'),

            Text::make('Phone')
                            ->sortable()
                            ->rules('required', 'max:254')
                            ->creationRules('unique:users,phone')
                            ->updateRules('unique:users,phone,{{resourceId}}'),

            Text::make('Date Of Birth', "date_of_birth")
                            ->sortable()
                            ->rules('required'),

            Password::make('Password')
                ->onlyOnForms()
                ->creationRules($this->passwordRules())
                ->updateRules($this->optionalPasswordRules()),

            Number::make('Credit')
                ->sortable()
                ->rules('required', 'numeric', 'min:0')
                ->step(0.01),

            HasMany::make('Credit', 'creditTransactions', CreditTransaction::class),

            HasMany::make('Orders', 'orders', Order::class),

            HasMany::make('Addresses'),
        ];
    }

    /**
     * Get the cards available for the request.
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
        return [
            (new AddCreditAction())
                ->showOnTableRow()
                ->showOnDetail()
                ->confirmText('Are you sure you want to add credit to this user?')
                ->confirmButtonText('Add Credit'),
        ];
    }
}
