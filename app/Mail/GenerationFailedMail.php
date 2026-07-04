<?php

namespace App\Mail;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;

/**
 * Sent when a video was set to publish/schedule without manual review and
 * the render failed - since nobody's watching the screen for the error,
 * this is the only way the user finds out (credits are already refunded by
 * the time this is sent, see PostAutoPublishService::handleGenerationFailed).
 */
class GenerationFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Post $post, public string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your video failed to render - credits refunded',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.generation_failed',
            with: [
                'reason' => $this->reason,
                'caption' => $this->post->caption,
                'siteUrl' => rtrim(Config::get('constant.frontend_url'), '/'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
