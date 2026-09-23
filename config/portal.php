<?php

// EntryPoint — the student-facing portal.

return [

    // Where students sign in. Emails render outside a request, so there's no
    // host to infer a link from — it has to be configured.
    'url' => rtrim(env('PORTAL_URL', 'http://localhost:3000'), '/'),

];
