<?php

namespace App\Console\Commands;

use App\Images\MediaService;
use App\Platform\EmbeddedSessionService;
use App\Products\ContentReviewService;
use Illuminate\Console\Command;

class RunRetention extends Command
{
    protected $signature = 'revo:retention:run';

    protected $description = 'Delete expired drafts, library images and embedded sessions';

    public function handle(ContentReviewService $review, MediaService $media, EmbeddedSessionService $sessions): int
    {
        $this->info('Drafts deleted: '.$review->pruneExpired());
        $this->info('Images expired: '.$media->expire());
        $this->info('Sessions pruned: '.$sessions->pruneExpired());

        return self::SUCCESS;
    }
}
