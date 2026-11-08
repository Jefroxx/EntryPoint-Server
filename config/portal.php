<?php

// EntryPoint — the student-facing portal.

return [

    // Where students sign in. This is the fallback: while a student is using the site, links in emails
    // follow the address their browser is on (see App\Support\PortalUrl), so a changing tunnel address
    // needs no edit here. Set it to the real domain once there is one.
    'url' => rtrim(env('PORTAL_URL', 'http://localhost:3000'), '/'),

    // Extra hosts that links in emails may point to, comma separated. ".example.com" also covers every
    // subdomain. localhost and PORTAL_URL's own host are always allowed.
    'allowed_hosts' => array_filter(array_map('trim', explode(',', (string) env('PORTAL_ALLOWED_HOSTS', '.trycloudflare.com')))),

    // Only addresses at this domain may register, e.g. "school.edu.ph". Blank allows any address.
    'student_email_domain' => ltrim((string) env('STUDENT_EMAIL_DOMAIN', ''), '@'),

    // How long the link in the "confirm your email" message works for.
    'verify_link_minutes' => (int) env('VERIFY_LINK_MINUTES', 60),

];
