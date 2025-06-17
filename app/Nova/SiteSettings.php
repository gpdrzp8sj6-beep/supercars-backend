<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Markdown;
use Laravel\Nova\Http\Requests\NovaRequest;

class SiteSettings extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\SiteSettings>
     */
    public static $model = \App\Models\SiteSettings::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = '';

    public static $orderBy = '';
    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [

    ];

    public static function authorizedToCreate(Request $request)
    {
        return false;  // disables the "Create" button and prevents creating new records
    }

public function authorizedToDelete(Request $request)
{
    return false;
}

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make("Instagram"),
            Text::make("Meta"),
            Text::make("Youtube"),
            Markdown::make("Competition Details", 'competition_details'),
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
