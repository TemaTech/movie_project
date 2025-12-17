<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Exception;

class BoxOfficeFetchError extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The error instance.
     *
     * @var \Exception
     */
    public $error;

    /**
     * The command name context.
     *
     * @var string
     */
    public $commandName;

    /**
     * Create a new message instance.
     *
     * @param Exception $error
     * @param string $commandName e.g., "日本歴代興行成績"
     */
    public function __construct(Exception $error, string $commandName)
    {
        $this->error = $error;
        $this->commandName = $commandName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "【エラー通知】{$this->commandName}取得処理でエラーが発生しました",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.box_office_error',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
