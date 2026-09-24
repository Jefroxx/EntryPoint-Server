<x-mail::message>
<span class="status-pill">&check; Registration approved</span>

# You're all set, {{ $firstName }}

A librarian has approved your registration, so your library account is now active. Sign in with the
email and password you registered with.

<x-mail::button :url="$signInUrl">
Sign in to the portal
</x-mail::button>

Once you're signed in, open **Library ID** to get the barcode you'll scan at the library entrance —
your visit streak starts on your first scan.

<x-slot:subcopy>
Didn't register for a library account? You can safely ignore this email.
</x-slot:subcopy>
</x-mail::message>
