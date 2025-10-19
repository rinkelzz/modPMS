# modPMS

Ein modulares Property-Management-System (PMS) für Hotels, entwickelt in PHP und MySQL. Dieses Repository enthält das Basis-Modul mit Dashboard, Kalender, Zimmerkategorien und integriertem Update-Workflow.

## Features Basis-Modul (Version 1.0.5)

- **Dashboard** mit tagesbasiertem Zimmerkalender (Zimmer auf Y-Achse, Tage auf X-Achse) und Schnellstatistik.
- **Zimmerkategorien-Verwaltung** inklusive Bearbeiten/Löschen und **Zimmerstamm** mit CRUD-Funktionen – alles direkt in MySQL gespeichert.
- **Zimmerübersicht** mit Beispielzimmern aus der Datenbank, die sich leicht erweitern lassen.
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

> **Hinweis:** Nach der Installation werden Zimmerkategorien und Zimmer direkt in der konfigurierten MySQL-Datenbank gespeichert. Passen Sie die Zugangsdaten bei Bedarf in `config/database.php` an.

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
