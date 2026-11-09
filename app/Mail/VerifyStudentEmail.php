<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyStudentEmail extends BrandedMail
{
    use SerializesModels;

    /** `$madeByAdmin`: an administrator made the account, so confirming is the only step (no approval after it). */
    public function __construct(public User $user, public string $verifyUrl, public bool $madeByAdmin = false)
    {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your email for your EntryPoint library account',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.students.verify',
            with: [
                'firstName' => $this->user->firstName,
                'verifyUrl' => $this->verifyUrl,
                'minutes'   => config('portal.verify_link_minutes'),
                'madeByAdmin' => $this->madeByAdmin,
            ],
        );
    }
}
