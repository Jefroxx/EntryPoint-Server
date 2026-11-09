<x-mail::message>
@if ($madeByAdmin)
<span class="status-pill">Confirm your email</span>

# Hi {{ $firstName }}, welcome to EntryPoint

The library administrator made an EntryPoint account for you with this email. Confirm it's yours, then sign in
with the temporary password the administrator gave you. You'll be asked to choose your own password.
@else
<span class="status-pill">Step 1 of 2 &middot; Confirm your email</span>

# Hi {{ $firstName }}, is this you?

You just registered for an EntryPoint library account with this email. Confirm it's yours and your
registration goes to a librarian for approval.
@endif

<x-mail::button :url="$verifyUrl">
Confirm my email
</x-mail::button>

The button works for {{ $minutes }} minutes. If it has expired, go to the sign-in page and choose
**Send a new link**.

<x-slot:subcopy>
Weren't expecting this? You can safely ignore this email; nothing happens unless the button is clicked.
If the button doesn't work, copy this link into your browser: <span class="break-all">[{{ $verifyUrl }}]({{ $verifyUrl }})</span>
</x-slot:subcopy>
</x-mail::message>
