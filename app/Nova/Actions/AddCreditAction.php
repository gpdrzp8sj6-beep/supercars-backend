<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Models\CreditTransaction;

class AddCreditAction extends Action
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Add Credit';

    /**
     * The text to be used for the action's confirm button.
     *
     * @var string
     */
    public $confirmButtonText = 'Add Credit';

    /**
     * The text to be used for the action's confirmation text.
     *
     * @var string
     */
    public $confirmText = 'Add credit to this user?';

    /**
     * Determine where the action redircts after execution.
     *
     * @return string
     */
    public function redirectTo()
    {
        return '/nova/resources/users';
    }

    /**
     * Determine if the action should be available for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function authorizedToSee(Request $request)
    {
        return true;
    }

    /**
     * Determine if the action should be available for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    public function authorizedToRun(Request $request, $model)
    {
        return true;
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $user) {
            $user->credit = $user->credit + $fields->amount;
            $user->save();

            CreditTransaction::create([
                'user_id' => $user->id,
                'amount' => $fields->amount,
                'type' => 'add',
                'description' => $fields->description,
            ]);
        }

        return ActionResponse::message('Credit added successfully!');
    }

    /**
     * Get the fields available on the action.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Number::make('Amount')
                ->rules('required', 'numeric', 'min:0.01')
                ->step(0.01)
                ->help('Enter the amount of credit to add'),

            Text::make('Description')
                ->rules('required', 'max:255')
                ->help('Reason for adding credit'),
        ];
    }
}
