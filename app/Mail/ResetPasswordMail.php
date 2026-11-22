<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The "choose a new password" link a student gets after pressing "Forgot password?". */
class ResetPasswordMail extends BrandedMail
{
    use SerializesModels;

    public function __construct(public User $user, public string $resetUrl)
    {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your EntryPoint password');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.students.reset-password',
            with: [
                'firstName' => $this->user->firstName,
                'resetUrl'  => $this->resetUrl,
                'minutes'   => (int) config('auth.passwords.users.expire'),
            ],
        );
    }
}
