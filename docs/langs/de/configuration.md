# Konfiguration

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/configuration.md) | [English](../en/configuration.md) | [Español](../es/configuration.md) | [中文](../zh/configuration.md) | [Français](../fr/configuration.md) | **Ausgewählt** |

[← Zurück zum README](README.md)

Die private Konfiguration befindet sich in `config/bot.ini` relativ zum Anwendungsverzeichnis. Erstelle die Datei anhand von [`config/bot.example.ini`](../../../config/bot.example.ini), ohne Geheimnisse in die öffentliche Vorlage zu schreiben. Alle Parameter werden in dieser INI-Datei gesetzt.

Die Datei wird vom integrierten INI-Parser gelesen, ohne Textwerte in boolesche Werte umzuwandeln. Behalte Anführungszeichen bei, schreibe numerische Einstellungen als dezimale Ganzzahlen und verwende `[]` für wiederholbare Listeneinträge. Starte die lokale Überwachung nach Änderungen neu; ein neuer `work-once`-Aufruf sowie jede neue Webhook-Anfrage lesen die aktuelle Datei.

## Betriebsarten und Befehle

Die Befehle sind in [`bin/bot.php`](../../../bin/bot.php) definiert. Ohne Argument wird `run` ausgeführt.

| Befehl | Betriebsart | Anforderungen |
| --- | --- | --- |
| `php bin/bot.php identify` | `polling` | Bot-Token und freie Sperre `var/bot.lock`; Profil, ID und KI-Einstellungen sind nicht erforderlich. |
| `php bin/bot.php run` | `polling` | Bot-Token, Telegram-Benutzer-ID, Profil, Anweisungen, KI-Einstellungen und Speicherzugriff. |
| `php bin/bot.php work-once` | `webhook` | Bot-Token, Telegram-ID, Webhook-Geheimnis, Profil, Anweisungen, KI-Einstellungen und Speicherzugriff. |
| `php bin/bot.php webhook set <https-url>` | Webhook-Verwaltung | Bot-Token, `TELEGRAM_RECEIVE_MODE=webhook`, gültiges `TELEGRAM_WEBHOOK_SECRET` und öffentliche HTTPS-Adresse. |
| `php bin/bot.php webhook info` | Webhook-Verwaltung | Nur Bot-Token; ID, Profil, KI und gewählter Empfangsmodus sind nicht erforderlich. |
| `php bin/bot.php webhook delete` | Webhook-Verwaltung | Nur Bot-Token; ID, Profil, KI und gewählter Empfangsmodus sind nicht erforderlich. |
| `public/webhook.php` über FPM | `webhook` | Bot-Token, Telegram-ID, Webhook-Geheimnis und vorbereitete Datenbank; KI und Profil werden in der HTTP-Anfrage nicht geladen. |

`webhook set` akzeptiert HTTPS-URLs mit öffentlichem DNS-Namen und Port 443, 80, 88 oder 8443. URLs mit Zugangsdaten, Query oder Fragment werden abgelehnt. `webhook info` zeigt Registrierung, ausstehende Updates und den letzten Fehler an, **gibt aber die registrierte URL nicht aus**. `webhook delete` behält ausstehende Updates bei (`drop_pending_updates=false`). Die Befehle öffnen SQLite nicht und verändern `config/bot.ini` nicht; das Wählen einer Betriebsart registriert keinen Webhook.

Im lokalen Archiv werden `identify` und `run` über das Menü mit dem mitgelieferten PHP aufgerufen; ein systemweites PHP ist dafür nicht nötig. `work-once` und die Webhook-Verwaltung gelten für die Serverinstallation aus Quellcode. `identify` nimmt eine nicht blockierende Sperre vor dem Bot-API-Aufruf und kann beim ersten Mal lediglich das private Verzeichnis `var/` und `bot.lock` erstellen.

## Telegram

| Schlüssel | Zweck und zulässige Werte | Erforderlich / Standardwert |
| --- | --- | --- |
| `TELEGRAM_BOT_TOKEN` | BotFather-Token im Format „Ziffern:Buchstaben/Ziffern/`_`/`-`“. Korrekte Form bedeutet nicht, dass das Token gültig ist. | Für jede Bot-Interaktion erforderlich; in der Vorlage leer. |
| `TELEGRAM_ALLOWED_USER_ID` | Numerische Telegram-Konto-ID, von der der Bot Angebote annimmt und an die er Ergebnisse sendet. Nach `/start` über `identify` oder Menü ermitteln. | Für `run`, `work-once` und Webhook-Empfang erforderlich; nicht für `identify` oder Webhook-Verwaltung; in der Vorlage leer. |
| `TELEGRAM_RECEIVE_MODE` | `polling` für lokale `run`/`identify`; `webhook` für FPM, `work-once` und `webhook set`. | Optional; fehlt der Wert, gilt `polling`. |
| `TELEGRAM_WEBHOOK_SECRET` | Geheimes Token zur Prüfung eingehender Webhook-Anfragen: 1–256 Zeichen aus `A–Z`, `a–z`, `0–9`, `_`, `-`. Entspricht `secret_token` bei `webhook set`. | Für Webhook-Empfang, `work-once` und `webhook set` erforderlich; nicht für `webhook info/delete`; in der Vorlage leer. |

Die Änderung von `TELEGRAM_RECEIVE_MODE` registriert oder löscht keinen Webhook; verwende die separaten Befehle `webhook set/info/delete`. Die HTTP-Prüfung liegt in [`WebhookReceiver`](../../../src/Application/WebhookReceiver.php); [`public/webhook.php`](../../../public/webhook.php) lädt die Einstellungen. Der Ablauf ist in der [Serveranleitung](vds-webhook.md#modi-wechseln) beschrieben.

## KI-Anbindung

Die Modellverbindung wird in `config/bot.ini` eingestellt. [`OpenAiCompatibleEvaluator`](../../../src/Analysis/OpenAiCompatibleEvaluator.php) prüft die Anforderungen.

| Schlüssel | Zweck und zulässige Werte | Erforderlich / Standardwert |
| --- | --- | --- |
| `AI_PROVIDER_LOCATION` | `local` oder `cloud`; bestimmt zulässige Adressen und die Notwendigkeit eines Schlüssels. | Optional; Standard `local`. |
| `AI_BASE_URL` | Basis-URL ohne `/chat/completions`; HTTP/HTTPS, ohne Benutzername, Passwort, Query, Fragment, Leer- und Steuerzeichen; höchstens 2048 Byte. | Erforderlich ohne URL-Ersatz; Beispiel `http://127.0.0.1:1234/v1`. |
| `AI_MODEL` | Nicht leere API-Modellkennung in UTF-8, höchstens 200 Byte, keine ASCII-Steuerzeichen. | Erforderlich; in der Vorlage leer. |
| `AI_API_KEY` | Schlüssel für `Authorization: Bearer`, UTF-8, höchstens 4096 Byte, keine ASCII-Steuerzeichen. | Bei `local` optional, sofern der Server keinen verlangt; bei `cloud` erforderlich und nicht leer; in der Vorlage leer. |
| `AI_MAX_ATTEMPTS` | Ganzzahl 1–5: Gesamtanzahl einschließlich des ersten Versuchs, getrennt für Analyse und Zustellung. | Optional; Standard `3`. |
| `AI_REQUEST_TIMEOUT_SECONDS` | Ganzzahl 30–1800: Limit einer KI-Anfrage in Sekunden. | Optional; Standard `180`; ein leerer Wert löst den Standard nicht aus. |

Für `local` sind `localhost`, `[::1]` sowie die IPv4-Bereiche `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12` und `192.168.0.0/16` zulässig. Namen im lokalen Netz und andere IPv6-Adressen sind nicht erlaubt. Bei `cloud` werden HTTPS und ein nicht leerer Schlüssel benötigt.

Das Verbindungs-Timeout ist fest auf 5 Sekunden gesetzt und nicht separat einstellbar. HTTP-Weiterleitungen und Proxys sind für KI-Anfragen abgeschaltet; TLS wird überprüft. [`CurlAiTransport`](../../../src/Analysis/Http/CurlAiTransport.php) setzt diese Grenzen durch. Vorübergehende Fehler führen zu verzögerten Wiederholungen; dauerhafte Fehler beenden die Verarbeitung ohne weitere Versuche. Mehr dazu in der [Modellanleitung](ai-models.md#zeitlimits-und-wiederholungen).

## Vorfilter

Der Vorfilter überspringt vordefinierte kurze Nachrichten ohne KI-Aufruf. Beide Listen sind standardmäßig leer. [`ConservativePrefilter`](../../../src/Analysis/ConservativePrefilter.php) führt die Regeln aus.

| Schlüssel | Zweck |
| --- | --- |
| `AI_PREFILTER_EXACT_REJECT_LINES[]` | Exakter Text der gesamten Nachricht, die sofort abgewiesen werden darf. |
| `AI_PREFILTER_AMBIGUITY_TERMS[]` | Wort oder Ausdruck, dessen Vorhandensein **die exakte Ablehnung aufhebt**. |

### Ein Ausdruck

```ini
; Nur bei Übereinstimmung der vollständigen Nachricht ablehnen.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
```

Eine Nachricht, die nur aus `Только ночная смена.` besteht, wird nach Entfernen äußerer Leerzeichen ohne Analyse übersprungen. `Только ночная смена. Есть варианты?` stimmt nicht mehr vollständig überein und wird an das Modell übergeben.

### Mehrere Ausdrücke und Ausnahmen

Jeder Ausdruck steht in **einer eigenen Zeile**. Das Beispiel enthält drei exakte Regeln und zwei Mehrdeutigkeitsbegriffe:

```ini
; Exakte Ablehnungen und Begriffe, die sie aufheben.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

Eine vollständige Übereinstimmung mit einer der drei Zeilen führt zur Ablehnung. Steht einer der beiden Mehrdeutigkeitsbegriffe in der Nachricht, wird die Ablehnung aufgehoben und der Text dem Modell übergeben. Exakter Vergleich unterscheidet Groß-/Kleinschreibung, die Begriffssuche nicht.

Jeder Listeneintrag enthält **eine nicht leere Zeichenfolge**, keine kommagetrennten Regeln. Mehrzeilige Stellenanzeigen werden nicht abgelehnt: verglichen wird der gesamte Nachrichtentext, nicht ein einzelner Satz. Reguläre Ausdrücke werden nicht verwendet.

## Profil und Speicher

Für folgende Pfade gibt es keine INI-Schlüssel; sie werden vom [CLI-Einstiegspunkt](../../../bin/bot.php) festgelegt.

| Pfad | Zweck |
| --- | --- |
| `config/profile/local/candidate.md` | Kandidatenprofil, für die Verarbeitung erforderlich. |
| `config/profile/local/instructions.md` | Modellanweisungen, für die Verarbeitung erforderlich. |
| `var/vacancies.sqlite` | Nachrichten, Ergebnisse und Warteschlange. |
| `var/bot.lock` | Gemeinsame Sperre für `run`, `identify` und cron `work-once`; Webhook-Anfragen verwenden SQLite-Transaktionen. |

Grenzen der Markdown-Dateien stehen im [Profilleitfaden](profile.md). Konfiguration, Profil und `var/` enthalten private Daten, sind von Git ausgeschlossen und müssen bei Updates erhalten bleiben.
