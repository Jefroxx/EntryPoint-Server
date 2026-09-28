<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentApproved extends BrandedMail
{
    use SerializesModels;

    public function __construct(public Student $student)
    {
        parent::__construct();
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
