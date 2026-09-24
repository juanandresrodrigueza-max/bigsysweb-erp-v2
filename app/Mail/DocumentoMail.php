<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DocumentoMail extends Mailable
{
    public function __construct(public Business $business, public string $asunto, public string $cuerpo, public ?array $adjunto = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto, replyTo: $this->business->email ? [$this->business->email] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.documento', with: ['b' => $this->business, 'cuerpo' => $this->cuerpo]);
    }

    public function attachments(): array
    {
        return $this->adjunto ? [Attachment::fromData(fn() => $this->adjunto[0], $this->adjunto[1])->withMime('application/pdf')] : [];
    }
}
