<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * Every EntryPoint email goes through the shared mail header, which shows the logo as `cid:entrypoint-logo`.
 * Markdown mailables render to a string before the message exists, so `$message->embed()` is unavailable
 * and this is the way the logo travels inline. Extend this instead of Mailable and the logo is always there.
 */
abstract class BrandedMail extends Mailable
{
    public const LOGO_CID = 'entrypoint-logo';

    public function __construct()
    {
        $this->withSymfonyMessage(function (Email $message) {
            $message->embedFromPath(public_path('images/entrypoint-logo.png'), self::LOGO_CID, 'image/png');
        });
    }
}
