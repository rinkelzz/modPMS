<?php

return [
    'name' => 'modPMS',
    'version' => '1.6.5',
    'repository' => [
        'url' => 'https://github.com/rinkelzz/modpms',
        'branch' => 'main',
    ],
    'modules' => [
        'dashboard' => [
            'title' => 'Dashboard',
            'description' => 'Übersicht mit Kalender, Anzeigeoptionen und Reservierungsstatus.'
        ],
        'reservations' => [
            'title' => 'Reservierungen',
            'description' => 'Aufenthalte verwalten und Historien nachvollziehen.'
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
        'settings' => [
            'title' => 'Einstellungen',
            'description' => 'Statusfarben anpassen, Sicherungen erstellen und Datenbanken aktualisieren.'
        ],
    ],
];
