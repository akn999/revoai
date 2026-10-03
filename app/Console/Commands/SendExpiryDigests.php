<?php

namespace App\Console\Commands;

use App\Images\MediaService;
use App\Mail\ImageExpiryDigest;
use App\Models\Merchant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendExpiryDigests extends Command
{
    protected $signature = 'revo:media:expiry-digest';

    protected $description = 'Email stores about library images that are about to expire';

    public function handle(MediaService $media): int
    {
        $sent = 0;

        foreach ($media->expiringSoon() as $merchantId => $images) {
            $merchant = Merchant::findBySallaId((int) $merchantId);

            if (! $merchant?->email || ! Cache::add("revo:expiry-digest:{$merchantId}:".now()->toDateString(), 1, now()->addDay())) {
                continue;
            }

            Mail::to($merchant->email)->send(new ImageExpiryDigest($merchant, $images->count(), $images->min('expires_at')));
            $sent++;
        }

        $this->info("Sent {$sent} digests.");

        return self::SUCCESS;
    }
}
