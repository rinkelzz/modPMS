# modPMS

Ein modulares Property-Management-System (PMS) für Hotels, entwickelt in PHP und MySQL. Dieses Repository enthält das Basis-Modul mit Dashboard, Kalender, Zimmerkategorien und integriertem Update-Workflow.

## Features Basis-Modul (Version 1.2.1)

- **Dashboard** mit tagesbasiertem Zimmerkalender (Zimmer auf Y-Achse, Tage auf X-Achse) inklusive Kategorie-Summen (gesamt/frei) und Schnellstatistik.
- **Zimmerkategorien-Verwaltung** inklusive Bearbeiten/Löschen und **Zimmerstamm** mit CRUD-Funktionen – alles direkt in MySQL gespeichert.
- **Zimmerübersicht** mit Beispielzimmern aus der Datenbank, die sich leicht erweitern lassen.
- **Gästeverwaltung** inklusive aller für den Meldeschein benötigten Stammdaten und Vollständigkeitsprüfung.
- **Systemupdates** direkt aus der Weboberfläche anstoßen – inklusive Git-Prüfungen, ZIP-Fallback und aussagekräftigen Fehlermeldungen.
- **Benutzerverwaltung** mit Rollen (Administrator/Mitarbeiter), Passwort-Reset und Login-Tracking.
- **Anmeldung & Logout** über `login.php` inkl. Session-Schutz des Dashboards.
- **Installationsassistent** (`public/install.php`) zur grafischen Einrichtung der MySQL-Datenbank inklusive Beispieltabellen.
- Responsive UI auf Basis von Bootstrap 5.

## Erste Schritte

1. Repository klonen und Abhängigkeiten bereitstellen:
   ```bash
   git clone https://github.com/rinkelzz/modpms.git
   cd modPMS
   ```
2. Das Projektverzeichnis als Webroot (z. B. Apache/Nginx) konfigurieren oder über den integrierten PHP-Server starten:
   ```bash
   php -S localhost:8000 -t public
   ```
3. Den Installationsassistenten unter <http://localhost:8000/install.php> aufrufen, die MySQL-Datenbank einrichten **und den ersten Administrationsbenutzer anlegen**.
4. Anschließend unter <http://localhost:8000/login.php> anmelden und das Dashboard aufrufen.
5. Aus Sicherheitsgründen `public/install.php` nach erfolgreicher Einrichtung entfernen oder sperren.

> **Hinweis:** Nach der Installation werden Zimmerkategorien und Zimmer direkt in der konfigurierten MySQL-Datenbank gespeichert. Passen Sie die Zugangsdaten bei Bedarf in `config/database.php` an.

## Update-Mechanismus

- Die aktuelle Version wird in `config/app.php` geführt. Bitte bei jedem Release anpassen (z. B. 1.0.2, 1.0.3 …).
- Über den Bereich **Systemupdates** im Dashboard lässt sich per Button ein Update starten. Ist Git verfügbar, wird ein `fetch/reset/pull` ausgeführt (inklusive automatischer Remote-Konfiguration). Scheitert dies oder steht Git nicht bereit, lädt der Updater ein ZIP-Archiv der gewählten Branch herunter und spielt es per PHP auf.
- Voraussetzung für den Git-Weg ist ein Checkout mit `.git` sowie ausreichende Rechte des Webserver-Benutzers. Für den ZIP-Fallback muss die PHP-Erweiterung `zip` aktiv sein und ausgehende HTTPS-Verbindungen erlaubt sein.

## Gästeverwaltung & Meldescheinvorbereitung

- Erfassung von Anrede, Name, Geburtsdatum, Nationalität und Kontaktdaten.
- Hinterlegung von Aufenthaltszeitraum, Reisezweck sowie Ausweis- und Adressdaten.
- Automatische Prüfung, ob alle Pflichtfelder für die Meldeschein-Erstellung ausgefüllt sind.
- Export-Schaltfläche als Platzhalter für den kommenden PDF-/Druck-Workflow.

## Nächste Module

- Ratenplan & Kalender
- Putzplan
- Externe API-Anbindungen
- Berichte

Feedback & Issues sind jederzeit willkommen!
