<x-mail::message>
<span class="status-pill">Password reset</span>

# Hi {{ $firstName }}, forgot your password?

Someone asked to reset the password of your EntryPoint library account. If it was you, choose a new one with the button below.

<x-mail::button :url="$resetUrl">
Choose a new password
</x-mail::button>

The button works once, for {{ $minutes }} minutes. After that, go to the sign-in page and choose
**Forgot password?** to get a new one.

<x-slot:subcopy>
Didn't ask for this? You can safely ignore this email: your password stays exactly as it is until the button is used.
If the button doesn't work, copy this link into your browser: <span class="break-all">[{{ $resetUrl }}]({{ $resetUrl }})</span>
</x-slot:subcopy>
</x-mail::message>
