<?php

namespace App\Mail;

use App\Models\Merchant;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ImageExpiryDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Merchant $merchant, public int $count, public ?CarbonInterface $firstExpiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Revo AI: library images are about to expire');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.image-expiry-digest');
    }
}
