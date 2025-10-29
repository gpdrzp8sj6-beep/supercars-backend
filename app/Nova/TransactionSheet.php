<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Actions\Action;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class TransactionSheet extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\TransactionSheet>
     */
    public static $model = \App\Models\TransactionSheet::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'filename';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id', 'filename',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        if ($request->isCreateOrAttachRequest()) {
            return [
                Select::make('Giveaway', 'giveaway_id')
                    ->options(\App\Models\Giveaway::pluck('title', 'id'))
                    ->required()
                    ->help('Select the giveaway to match transactions against.'),

                File::make('Transaction Sheet', 'file_path')
                    ->acceptedTypes('.csv')
                    ->help('Upload the TP Transactions CSV file to process and match with orders.')
                    ->required(),

                Text::make('Filename')
                    ->help('Optional custom filename for this transaction sheet.')
                    ->hideFromIndex(),
            ];
        }

        return [
            ID::make()->sortable(),

            Text::make('Giveaway', function () {
                return $this->giveaway ? $this->giveaway->title : 'N/A';
            })->onlyOnIndex(),

            Text::make('Filename')
                ->sortable(),

            Number::make('Total Transactions', function () {
                return $this->summary['total_transactions'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Matched Orders', function () {
                return $this->summary['matched_orders'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Unmatched Transactions', function () {
                return $this->summary['unmatched_transactions'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Unmatched Orders', function () {
                return $this->summary['unmatched_orders'] ?? 0;
            })->onlyOnIndex(),

            Currency::make('Total Revenue', function () {
                return $this->summary['total_revenue'] ?? 0;
            })->currency('GBP')->onlyOnIndex(),

            Currency::make('Total Credit Used', function () {
                return $this->summary['credit_used_total'] ?? 0;
            })->currency('GBP')->onlyOnIndex(),

            Number::make('Paid with Credit Only', function () {
                return $this->summary['paid_with_credit'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Paid with Gateway', function () {
                return $this->summary['paid_with_gateway'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Matched (Completed)', function () {
                return $this->summary['status_breakdown']['Matched (Completed)'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Matched (Failed)', function () {
                return $this->summary['status_breakdown']['Matched (Failed)'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Matched (Pending)', function () {
                return $this->summary['status_breakdown']['Matched (Pending)'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Order ID Mismatch', function () {
                return $this->summary['status_breakdown']['Order ID Mismatch'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Invalid Order ID', function () {
                return $this->summary['status_breakdown']['Invalid Order ID'] ?? 0;
            })->onlyOnIndex(),

            Number::make('Unmatched', function () {
                return $this->summary['status_breakdown']['Unmatched'] ?? 0;
            })->onlyOnIndex(),

            KeyValue::make('Summary')
                ->onlyOnDetail(),

            DateTime::make('Created At')
                ->onlyOnDetail(),

            DateTime::make('Updated At')
                ->onlyOnDetail(),
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
     * Get the lenses available for the lens.
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
            new \App\Nova\Actions\DownloadTransactionReport,
            new \App\Nova\Actions\ShowMissingOrdersAnalytics,
        ];
    }
}
