<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentRejected extends BrandedMail
{
    use SerializesModels;

    public function __construct(public Student $student)
    {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Update on your library registration',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.students.rejected',
            with: [
                'firstName' => $this->student->user->firstName,
            ],
        );
    }
}
