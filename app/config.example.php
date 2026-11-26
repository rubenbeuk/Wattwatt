<?php
// Kopieer naar voorraad-config.php, NAAST de map public_html (dus niet erin).
return [
    'app_name' => 'Watt Watt Voorraad',
    'app_url'  => 'https://voorraad.wattwatt.nl',
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'voorraad',
        'user' => 'voorraad',
        'pass' => 'VUL-IN',
    ],
    // Overstap Pipedrive Projects: alleen-lezen API-sleutel (Pipedrive > Persoonlijke voorkeuren > API). Mag na de overstap weer weg.
    'pipedrive_token' => '',
    // Webformulier service (wattwatt.nl/service) stuurt tickets naar /api_ticket.php met deze sleutel in de header X-WW-Sleutel.
    // Maak een lange willekeurige reeks, bijv.:  php -r 'echo bin2hex(random_bytes(24));'
    'ticket_sleutel' => '',
];
