@props(['url'])
<tr>
<td class="header">
<table class="brand" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-bar">&nbsp;</td>
</tr>
<tr>
<td class="brand-cell" align="center">
<a href="{{ $url }}" style="display: inline-block;">
{{-- Attached inline by the Mailable (see StudentApproved::LOGO_CID) rather than hotlinked, so
     Outlook and Gmail show it without the recipient clicking "download pictures". --}}
<img src="cid:{{ \App\Mail\StudentApproved::LOGO_CID }}" class="logo" width="168" alt="{{ config('app.name') }}">
</a>
</td>
</tr>
</table>
</td>
</tr>
