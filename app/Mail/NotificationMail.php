<?php

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Notification $notification,
        public string $signedUrl,
        public string $expiresAt,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.notification',
            with: [
                'body' => $this->notification->body,
                'signedUrl' => $this->signedUrl,
                'hasLink' => $this->notification->link !== null,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
