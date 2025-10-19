# modPMS

Ein modulares Property-Management-System (PMS) für Hotels, entwickelt in PHP und MySQL. Dieses Repository enthält das Basis-Modul mit Dashboard, Kalender, Zimmerkategorien und integriertem Update-Workflow.

## Features Basis-Modul (Version 1.0.3)

- **Dashboard** mit tagesbasiertem Zimmerkalender (Zimmer auf Y-Achse, Tage auf X-Achse) und Schnellstatistik.
- **Zimmerkategorien-Verwaltung** mit einfacher JSON-Speicherung (lokal) für einen schnellen Start.
- **Zimmerübersicht** mit Beispiel-Zimmern, die sich leicht erweitern lassen.
- **Systemupdates** direkt aus der Weboberfläche via `git` anstoßen.
- **Installationsassistent** (`public/install.php`) zur grafischen Einrichtung der MySQL-Datenbank inklusive Beispieltabellen.
- Responsive UI auf Basis von Bootstrap 5.

## Erste Schritte

1. Repository klonen und Abhängigkeiten bereitstellen:
   ```bash
   git clone https://github.com/your-org/modPMS.git
   cd modPMS
   ```
2. Das Projektverzeichnis als Webroot (z. B. Apache/Nginx) konfigurieren oder über den integrierten PHP-Server starten:
   ```bash
   php -S localhost:8000 -t public
   ```
3. Den Installationsassistenten unter <http://localhost:8000/install.php> aufrufen und die MySQL-Datenbank einrichten.
4. Anschließend das Dashboard öffnen: <http://localhost:8000>
5. Aus Sicherheitsgründen `public/install.php` nach erfolgreicher Einrichtung entfernen oder sperren.

> **Hinweis:** Für produktive Nutzung sollten Zimmerkategorien und Zimmerstammdaten in einer MySQL-Datenbank persistiert werden. Die JSON-Dateien `storage/room_categories.json` und `storage/rooms.json` sind als leichtgewichtiger Einstieg gedacht.

## Update-Mechanismus

- Die aktuelle Version wird in `config/app.php` geführt. Bitte bei jedem Release anpassen (z. B. 1.0.2, 1.0.3 …).
- Über den Bereich **Systemupdates** im Dashboard lässt sich per Button ein `git fetch/reset/pull` starten.
- Voraussetzung ist, dass das System unter einem Git-Checkout läuft und der Webserver Benutzer ausreichende Rechte für `git` besitzt.

## Nächste Module

- Ratenplan & Kalender
- Putzplan
- Externe API-Anbindungen
- Kundenverwaltung
- Berichte

Feedback & Issues sind jederzeit willkommen!
