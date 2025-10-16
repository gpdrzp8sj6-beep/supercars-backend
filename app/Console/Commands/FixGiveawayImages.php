<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Giveaway;

class FixGiveawayImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-giveaway-images {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update all giveaway images to full URLs in the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $baseUrl = config('app.url') . '/storage';

        $giveaways = Giveaway::whereNotNull('images')->where('images', '!=', '[]')->get();

        $this->info("Found {$giveaways->count()} giveaways with images");

        foreach ($giveaways as $giveaway) {
            $this->line("Processing giveaway {$giveaway->id} ({$giveaway->title})");

            $images = $giveaway->images; // This will be an array due to casting

            if (!is_array($images) || empty($images)) {
                $this->warn("Giveaway {$giveaway->id} has no valid images, skipping");
                continue;
            }

            // Check if images are already correct full URLs
            $needsUpdate = false;
            $correctBaseUrl = config('app.url') . '/storage';
            
            foreach ($images as $image) {
                if (!str_starts_with($image, $correctBaseUrl)) {
                    $needsUpdate = true;
                    break;
                }
            }

            if (!$needsUpdate) {
                $this->info("Giveaway {$giveaway->id} already has correct URLs, skipping");
                continue;
            }

            // Convert to correct full URLs
            $fullUrlImages = array_map(function ($image) use ($correctBaseUrl) {
                if (str_starts_with($image, $correctBaseUrl)) {
                    return $image; // Already correct
                }
                // Extract the relative path from any existing full URL
                if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                    // Remove the base URL part to get the relative path
                    $path = str_replace(['http://0.0.0.0:3000/', 'https://', 'http://'], '', $image);
                    return $correctBaseUrl . '/' . $path;
                }
                // It's already a relative path
                return $correctBaseUrl . '/' . $image;
            }, $images);

            if ($dryRun) {
                $this->info("Would update giveaway {$giveaway->id} images from " . json_encode($images) . " to " . json_encode($fullUrlImages));
            } else {
                $giveaway->images = $fullUrlImages;
                $giveaway->save();
                $this->info("Updated giveaway {$giveaway->id} images");
            }
        }

        $this->info('Fix completed');
    }
}
