<x-mail::message>
<span class="status-pill">Step 1 of 2 &middot; Confirm your email</span>

# Hi {{ $firstName }}, is this you?

You just registered for an EntryPoint library account with this email. Confirm it's yours and your
registration goes to a librarian for approval.

<x-mail::button :url="$verifyUrl">
Confirm my email
</x-mail::button>

The button works for {{ $minutes }} minutes. If it has expired, sign in to the portal and choose
**Send a new link**.

<x-slot:subcopy>
Didn't register for a library account? You can safely ignore this email; nothing happens unless the button is clicked.
If the button doesn't work, copy this link into your browser: <span class="break-all">[{{ $verifyUrl }}]({{ $verifyUrl }})</span>
</x-slot:subcopy>
</x-mail::message>
