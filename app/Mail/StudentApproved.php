<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class StudentApproved extends Mailable
{
    use SerializesModels;

    /**
     * Referenced as `cid:entrypoint-logo` by the shared mail header. Markdown mailables render to a
     * string before the message exists, so `$message->embed()` is unavailable and this is the way
     * the logo travels inline — any future mailable using that header has to embed it too.
     */
    public const LOGO_CID = 'entrypoint-logo';

    public function __construct(public Student $student)
    {
        $this->withSymfonyMessage(function (Email $message) {
            $message->embedFromPath(public_path('images/entrypoint-logo.png'), self::LOGO_CID, 'image/png');
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your library account is approved — sign in now',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.students.approved',
            with: [
                'firstName' => $this->student->user->firstName,
                'signInUrl' => config('portal.url'),
            ],
        );
    }
}
