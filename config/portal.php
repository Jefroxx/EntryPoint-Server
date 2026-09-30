<?php

// EntryPoint — the student-facing portal.

return [

    // Where students sign in. Emails render outside a request, so there's no
    // host to infer a link from — it has to be configured.
    'url' => rtrim(env('PORTAL_URL', 'http://localhost:3000'), '/'),

    // Only addresses at this domain may register, e.g. "school.edu.ph". Blank allows any address.
    'student_email_domain' => ltrim((string) env('STUDENT_EMAIL_DOMAIN', ''), '@'),

    // How long the link in the "confirm your email" message works for.
    'verify_link_minutes' => (int) env('VERIFY_LINK_MINUTES', 60),

];
