<?php

return [
    'name' => 'modPMS',
    'version' => '1.0.3',
    'repository' => [
        'url' => 'https://github.com/your-org/modPMS',
        'branch' => 'main',
    ],
    'modules' => [
        'dashboard' => [
            'title' => 'Dashboard',
            'description' => 'Übersicht mit Kalender und Schnellstatistiken.'
        ],
        'rooms' => [
            'title' => 'Zimmerkategorien',
            'description' => 'Verwaltung von Kategorien, Kapazitäten und Status.'
        ],
        'updates' => [
            'title' => 'Systemupdates',
            'description' => 'Version anzeigen und Updates aus GitHub abrufen.'
        ],
    ],
];
