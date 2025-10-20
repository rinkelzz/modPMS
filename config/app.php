<?php

return [
    'name' => 'modPMS',
    'version' => '1.4.0',
    'repository' => [
        'url' => 'https://github.com/rinkelzz/modpms',
        'branch' => 'main',
    ],
    'modules' => [
        'dashboard' => [
            'title' => 'Dashboard',
            'description' => 'Übersicht mit Kalender und Schnellstatistiken.'
        ],
        'guests' => [
            'title' => 'Gästeverwaltung',
            'description' => 'Stammdaten für Gäste und Firmen inkl. Meldeschein-relevanter Felder.'
        ],
        'rooms' => [
            'title' => 'Zimmerkategorien',
            'description' => 'Verwaltung von Kategorien, Kapazitäten und Status.'
        ],
        'updates' => [
            'title' => 'Systemupdates',
            'description' => 'Version anzeigen und Updates aus GitHub abrufen.'
        ],
        'users' => [
            'title' => 'Benutzerverwaltung',
            'description' => 'Zugangsdaten verwalten und Rollen steuern.'
        ],
    ],
];
