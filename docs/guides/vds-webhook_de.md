# Server: Webhook und cron

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](vds-webhook.md) | [English](vds-webhook_en.md) | [Español](vds-webhook_es.md) | [中文](vds-webhook_zh.md) | [Français](vds-webhook_fr.md) | **Ausgewählt** |

[← Zurück zum README](../readme/README_de.md)

Der Server empfängt Stellen über HTTPS, speichert sie in SQLite und verarbeitet sie nach Zeitplan. Du kannst dem Bot Ausschreibungstext senden oder Nachrichten aus anderen Chats weiterleiten.

[`public/webhook.php`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/public/webhook.php) speichert eingehende Nachrichten. `php bin/bot.php work-once` führt KI-Bewertungen durch und sendet Ergebnisse. Für ein Token entweder diesen Modus **oder** lokales Polling verwenden, nicht beide gleichzeitig.

## Umgebung vorbereiten

Lege die Repository-Dateien in ein dauerhaftes Serververzeichnis **außerhalb** des öffentlichen Website-Verzeichnisses.

Benötigt werden PHP `^8.5` für CLI und PHP-FPM, Composer, HTTPS mit gültigem Zertifikat und die Erweiterungen `curl`, `json`, `mbstring`, `PDO`, `pdo_sqlite`. Prüfe Erweiterungen für CLI und FPM getrennt, da sich ihre Einstellungen unterscheiden können. Anforderungen stehen in [`composer.json`](../../composer.json).

Führe im Stammverzeichnis der Serverinstallation aus:

```bash
# Abhängigkeiten gemäß composer.lock des Projekts installieren.
composer install
```

Nutze die `composer.lock` des Projekts. Composer dient der Installation, nicht der Verarbeitung eingehender Webhooks.

Richte HTTP-Server und dedizierten PHP-FPM-Pool mit den Mitteln deines Systems ein:

- DocumentRoot zeigt **ausschließlich** auf `public/`; einziger öffentlicher PHP-Einstieg ist `/webhook.php`.
- Verzeichnislisten, andere PHP-Ausführungspfade und Aliase/Ersatzrouten zum Projektstamm sperren.
- `config/`, `vendor/`, `var/`, `.git/` und Quellcode dürfen nicht per HTTP erreichbar sein.
- Anfragekörper auf 256 KiB begrenzen; die Anwendung prüft diese Grenze ebenfalls.
- `display_errors` für FPM deaktivieren. Anfrageinhalte, Tokens, Geheimnis-Header und Ausschreibungstexte dürfen nicht in HTTP-Logs erscheinen.

Konkrete Direktiven hängen vom HTTP-Server ab. Prüfe vor der Webhook-Registrierung von außen HTTPS und den Schutz privater Pfade.

## Konfiguration und Profil erstellen

Falls `config/bot.ini` fehlt, kopiere das [Beispiel](../../config/bot.example.ini). Bearbeite die Datei im Editor, statt Tokens in Terminalbefehle einzugeben.

1. Erstelle mit [BotFather](https://t.me/BotFather) einen Bot und speichere das Token in `TELEGRAM_BOT_TOKEN`.
2. Solange `polling` gewählt und kein Webhook registriert ist, sende direkt `/start` und führe `php bin/bot.php identify` aus. Bei belegtem `var/bot.lock` zuerst den anderen Polling-Prozess stoppen.
3. Trage deine eigene ID in `TELEGRAM_ALLOWED_USER_ID` ein.
4. Bereite `config/profile/local/candidate.md` und `config/profile/local/instructions.md` nach der [Profilanleitung](../../config/profile/README_de.md) vor.
5. Setze `AI_PROVIDER_LOCATION`, `AI_BASE_URL`, `AI_MODEL` und gegebenenfalls `AI_API_KEY`. Die [Modellanleitung](ai-models_de.md) beschreibt Verbindung und Datenübertragung.

Für einen Modellserver auf demselben VDS ist `http://127.0.0.1:1234/v1` im Modus `local` zulässig. Läuft das Modell auf einem anderen Rechner, verwende eine vom VDS aus erreichbare private IPv4. Cloudbetrieb erfordert HTTPS und API-Schlüssel.

Nach Feststellung der Benutzer-ID die Einstellungen ändern:

```ini
; Webhook aktivieren und das leere Geheimnis durch einen generierten Wert ersetzen.
TELEGRAM_RECEIVE_MODE = "webhook"
TELEGRAM_WEBHOOK_SECRET = ""
```

Ersetze den leeren Wert durch 1–256 zufällige Zeichen aus `A–Z`, `a–z`, `0–9`, `_`, `-`. Beispiel für 64 Zeichen mit PHP:

```bash
# Geheimnis erzeugen, ohne es in der Shell-History zu hinterlassen.
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Kopiere die Ausgabe nach `TELEGRAM_WEBHOOK_SECRET` in der privaten `config/bot.ini`; nicht in Logs oder Screenshots veröffentlichen. `webhook set` überträgt es als `secret_token` an Telegram. **Nicht** an die URL anhängen.

Alle Parameter stehen in der [Konfigurationsreferenz](../reference/configuration_de.md).

## Berechtigungen und SQLite vorbereiten

CLI und FPM müssen **denselben absoluten Installationspfad** und dieselbe Datenbank `var/vacancies.sqlite` nutzen. Am besten einen dedizierten unprivilegierten Anwendungsbenutzer für cron und PHP-FPM verwenden.

Der Benutzer muss Code, `vendor/`, INI und Profil lesen und DB sowie `var/` beschreiben dürfen: SQLite erstellt ein Journal neben der DB, CLI die Datei `bot.lock`. Andere Benutzer dürfen keinen Zugriff auf Konfiguration und Daten erhalten. Bei unterschiedlichen CLI/FPM-Benutzern gezielte ACLs einrichten, einschließlich Standard-ACLs für neue Dateien. CLI/FPM verwenden `umask(0077)`, daher reicht eine gemeinsame Gruppe nicht aus.

Rechte unter **beiden** Identitäten prüfen. Keine pauschalen rekursiven `chown` oder `chmod` über das gesamte Projekt ausführen.

Vor Registrierung des Webhooks als cron-Benutzer ausführen:

```bash
# Erstellt das DB-Schema und kann ausstehende Aufgaben bearbeiten: KEIN dry-run.
php bin/bot.php work-once
```

Nutze die passende PHP-CLI-Version. Der Befehl prüft Konfiguration und Profil, erstellt bei Bedarf `var/` und DB-Schema und **verarbeitet bereits wartende Aufgaben**. Der Webhook kann nach Erstellung der DB Nachrichten entgegennehmen.

Vor Updates INI, Profil und SQLite bei gestoppten Prozessen privat sichern. Bestehende Datenbank nicht durch eine leere ersetzen und Warteschlange nicht zum Test löschen.

## Webhook registrieren

Sicherstellen, dass Telegram `/webhook.php` über HTTPS erreicht. Ohne Geheimnis-Header antwortet der Server mit HTTP 403, auf GET mit HTTP 405. Vor Registrierung alle `run`- und `identify`-Prozesse dieses Bots stoppen: Polling und Webhook dürfen nicht parallel laufen.

Bei einer Quellcode-Installation die eingebauten Befehle `php bin/bot.php webhook` verwenden. Sie lesen Token und gegebenenfalls Geheimnis aus der privaten `config/bot.ini`; Eingabe im Terminal ist unnötig.

| Befehl | Funktion |
| --- | --- |
| `php bin/bot.php webhook set <https-url>` | Webhook unter HTTPS-Adresse registrieren; benötigt Modus `webhook` und gültiges INI-Geheimnis. |
| `php bin/bot.php webhook info` | Registrierungszustand, Zahl anstehender Updates und letzte Zustellfehlerinformation. |
| `php bin/bot.php webhook delete` | Registrierung entfernen, ohne anstehende Updates zu löschen. |

Die Anwendung verwendet die offiziellen Methoden [setWebhook](https://core.telegram.org/bots/api#setwebhook), [getWebhookInfo](https://core.telegram.org/bots/api#getwebhookinfo) und [deleteWebhook](https://core.telegram.org/bots/api#deletewebhook). Die Registrierung setzt `allowed_updates=["message"]`, `drop_pending_updates=false`, `max_connections=1`. Beim Löschen gilt ebenfalls `drop_pending_updates=false`.

### Webhook verwalten

Mit `TELEGRAM_RECEIVE_MODE = "webhook"` und nicht leerem `TELEGRAM_WEBHOOK_SECRET` im Installationsstamm ausführen (Beispiel durch **eigene öffentliche HTTPS-Domain** ersetzen):

```bash
# Eigene HTTPS-Adresse registrieren und Status prüfen.
php bin/bot.php webhook set 'https://bot.example.org/webhook.php'
php bin/bot.php webhook info
```

Die Adresse für `set` muss eine öffentliche DNS-Domain und HTTPS-Port 443, 80, 88 oder 8443 verwenden. URL-Anmeldedaten, Query und Fragment sind nicht erlaubt. Bei Erfolg zeigt `set` `Webhook зарегистрирован.` an. `info` meldet Registrierung, anstehende Updates und Fehler, **zeigt jedoch nicht die registrierte URL und vergleicht sie nicht mit deiner Angabe**.

Nach der Prüfung cron aktivieren. Zum Abmelden:

```bash
# Registrierung entfernen, anstehende Updates erhalten und Status prüfen.
php bin/bot.php webhook delete
php bin/bot.php webhook info
```

Nach `delete` wird `Webhook удалён. Ожидающие обновления сохранены.` erwartet, danach `Webhook не зарегистрирован.`. `info` und `delete` benötigen nur ein gültiges Bot-Token und funktionieren auch mit ausgewähltem `polling`. SQLite, Profil und KI-Einstellungen sind hierfür nicht nötig. Keine temporären PHP-Skripte mit eigenen Bot-API-Aufrufen erstellen.

## Verarbeitung per cron starten

Einmal pro Minute unter dem zugewiesenen Benutzer ausführen. **Beide** absoluten Pfade ersetzen:

```cron
# Jede Minute ausführen; beide absoluten Pfade ersetzen.
* * * * * /absolute/path/to/php8.5 /absolute/path/to/project/bin/bot.php work-once
```

Das cron-Arbeitsverzeichnis ist unerheblich: Der [CLI-Einstiegspunkt](../../bin/bot.php) bestimmt Pfade anhand seiner eigenen Position. cron-Ausgabe nur in privates Log oder Administratorpostfach leiten, niemals in öffentliche Verzeichnisse.

Der Befehl übernimmt die gemeinsame Sperre `var/bot.lock`, nimmt unterbrochene Versuche wieder auf und verarbeitet bis zu 10 Stapel. Nach 20 Sekunden werden keine neuen Stapel gestartet; bereits laufende Anfragen können bis zu `AI_REQUEST_TIMEOUT_SECONDS` (30–1800 Sekunden) dauern. Ein belegter Worker lässt den nächsten cron-Lauf aus, statt parallele Bewertungen auszuführen. Siehe [`BotRunner::workOnce()`](../../src/Application/BotRunner.php).

Sende dem Bot eine kurze erfundene Ausschreibung. Zuerst `Вакансия сохранена.` erwarten, nach cron die Startmeldung und das Ergebnis. Bei Netzwerkfehlern können Empfangsbestätigung und Startmeldung fehlen, ohne dass die gespeicherte Nachricht verloren geht.

## Empfang und Zustellfehler

[`WebhookReceiver`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/src/Application/WebhookReceiver.php) prüft `X-Telegram-Bot-Api-Secret-Token` **vor** dem JSON-Parsing und Öffnen von SQLite. Update, Nachricht und Aufgabe werden in einer Transaktion gespeichert. HTTP 204 wird erst nach erfolgreicher Speicherung in SQLite gesendet. Dieselbe `update_id` erzeugt bei wiederholter Zustellung keine neue Aufgabe; Updates können ungeordnet eintreffen.

| HTTP-Code | Maßnahme |
| --- | --- |
| 400 | JSON-Format und Pflichtfelder prüfen. |
| 403 | Geheimnis in INI/Registrierung und Weitergabe des Headers an FPM kontrollieren. |
| 405 | POST nutzen; GET testet nur die Ablehnung. |
| 413 | Körpergröße prüfen: höchstens 256 KiB. |
| 415 | `Content-Type: application/json` setzen. |
| 503 | Modus, Konfiguration, Composer-Autoloader, bestehende DB und SQLite-Rechte prüfen. |

SQLite wartet höchstens 5 Sekunden auf eine Sperre. Bei fehlgeschlagener Speicherung wird 503 geliefert, damit Telegram erneut zustellen kann; die Wiederholungen sind begrenzt, Fehler zügig beheben. Erst nach SQLite-Speicherung wird bestätigt. Ein wiederholtes Update erzeugt keine zweite Aufgabe. Siehe [Duplikaterkennung](message-processing_de.md).

Die Meldung zum Analysebeginn wird pro Stelle nur einmal versucht. Eine fertige Bewertung wird vor dem Versand gespeichert und bei Zustellfehler nicht erneut berechnet. `AI_MAX_ATTEMPTS` gilt getrennt für Analyse und Zustellung. Endgültig fehlgeschlagene Aufgaben werden nicht automatisch neu erzeugt. Siehe [Ergebnisformate und Zustände](../reference/analysis-result_de.md).

## Modi wechseln

**Von der lokalen Anwendung zum Server:**

1. Überwachung und laufendes `identify` stoppen; Ende begonnener Arbeiten abwarten.
2. Bei gestoppten Prozessen `config/bot.ini`, Profil und **vollständiges `var/`** samt bestehender SQLite-DB zum Server übertragen. Keine zwei Kopien mit demselben Token und unterschiedlichen Daten parallel betreiben.
3. PHP-FPM, HTTPS, Rechte und cron-Zeitplan vorbereiten, **cron aber noch nicht aktivieren und Webhook noch nicht registrieren**. Gemeinsame CLI/FPM-DB sicherstellen.
4. `TELEGRAM_RECEIVE_MODE = "webhook"` und Geheimnis setzen. Als cron-Benutzer `php bin/bot.php work-once` zur Schema-Vorbereitung ausführen. **Das ist kein Diagnose-dry-run:** Bei wartenden Aufgaben kann der Befehl KI aufrufen und Nachrichten senden. Falls du die Warteschlange noch nicht verarbeiten willst, `work-once` nicht bloß für eine Konfigurationsprüfung starten.
5. Eigene HTTPS-Adresse mit `php bin/bot.php webhook set 'https://bot.example.org/webhook.php'` registrieren und Beispiel ersetzen. `webhook info` prüfen und cron aktivieren.

Während des Wechsels nur eine aktuelle Datenkopie verwenden. Eine DB-Übertragung stellt keine von Telegram bereits bestätigten oder verworfenen Updates wieder her.

**Vom Server zur lokalen Anwendung:**

1. cron deaktivieren und Abschluss aller aktiven Worker abwarten.
2. `php bin/bot.php webhook delete` ausführen (anstehende Updates bleiben erhalten) und mit `php bin/bot.php webhook info` die Ausgabe `Webhook не зарегистрирован.` prüfen.
3. Abschluss laufender PHP-FPM-Anfragen abwarten und den öffentlichen Webhook-Handler deaktivieren, damit er nicht mehr in die Datenbank schreiben kann.
4. Mit gestoppten Handlern dieselbe DB und privaten Dateien behalten; beim Umzug `var/` vollständig kopieren.
5. `TELEGRAM_RECEIVE_MODE = "polling"` einstellen und lokale Anwendung oder `php bin/bot.php run` aus den Quellen starten.

Cursor, Tabelle empfangener Updates und Nachrichten nicht manuell löschen. Nach langer Inaktivität setzt Polling einen unzuverlässigen ausgehenden `offset` zurück, ohne Daten zu löschen. Mit ausgewähltem `webhook` sind `run` und `identify` nicht verfügbar; eine INI-Änderung registriert oder entfernt keinen Webhook: dafür `webhook set` und `webhook delete` verwenden.

Bei Fehlern [lokale Hinweise](local-gum_de.md#wenn-etwas-nicht-funktioniert) und [ausführliche Fehlerbehebung](troubleshooting_de.md) lesen.
