<?php
// Copy this file to config.php. Keep config.php private and outside Git.
return [
    'group_name' => 'Unser Familienkalender',
    'sender_email' => 'erinnerung@familie.xyz',
    // Absolute public installation URL, including trailing slash.
    // Used for mail links, canonical URLs and social preview image URLs.
    'base_url' => 'https://kalender.example.org/',
    // Optional private photo in src/assets; empty uses the built-in design.
    'background_image' => '',
    'timezone' => 'Europe/Berlin',
    'mail_enabled' => false,
    // Generate once: php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
    // Needed for each account's first remote login with the public start password.
    'setup_key' => 'BITTE-ERSETZEN-DURCH-EINEN-ZUFAELLIGEN-CODE',
    // Applied only when data/setup.json does not exist yet.
    'initial_members' => [
        'mama' => ['name' => 'Mama', 'email' => 'mama@familie.xyz'],
        'papa' => ['name' => 'Papa', 'email' => 'papa@familie.xyz'],
    ],
];
