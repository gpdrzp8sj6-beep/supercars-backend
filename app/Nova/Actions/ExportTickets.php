<?php

namespace App\Nova\Actions;

use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportTickets extends Action
{
    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Export Tickets (CSV)';

    /**
     * Perform the action on the given models.
     *
     * @param  \Laravel\Nova\Fields\ActionFields  $fields
     * @param  \Illuminate\Support\Collection  $models
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $headers = ['ID','Order ID','Giveaway ID','Giveaway Title','User ID','User Name','User Email','Numbers','Is Winner','Winning Ticket','Created At'];

        $lines = [];
        $lines[] = implode(',', $this->escapeRow($headers));

        foreach ($models as $ticket) {
            $order = $ticket->order()->with('user')->first();
            $giveaway = $ticket->giveaway()->first();

            $row = [
                $ticket->id,
                optional($order)->id,
                optional($giveaway)->id,
                optional($giveaway)->title,
                optional(optional($order)->user)->id,
                optional(optional($order)->user)->fullName,
                optional(optional($order)->user)->email,
                is_string($ticket->numbers) ? $ticket->numbers : json_encode($ticket->numbers),
                $ticket->is_winner ? '1' : '0',
                $ticket->winning_ticket,
                $ticket->created_at,
            ];
            $lines[] = implode(',', $this->escapeRow($row));
        }

        $content = implode("\n", $lines) . "\n";

        $filename = 'tickets-' . now()->format('Ymd-His') . '-' . Str::random(6) . '.csv';
        $path = 'exports/' . $filename;
        Storage::disk('public')->put($path, $content);

        return Action::download(Storage::disk('public')->url($path), $filename);
    }

    /**
     * Escape CSV row values.
     *
     * @param array $row
     * @return array
     */
    protected function escapeRow(array $row): array
    {
        return array_map(function ($value) {
            $value = (string) $value;
            $needsQuotes = str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n");
            $value = str_replace('"', '""', $value);
            return $needsQuotes ? '"' . $value . '"' : $value;
        }, $row);
    }
}
