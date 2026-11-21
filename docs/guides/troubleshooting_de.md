# Fehlerbehebung

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](troubleshooting.md) | [English](troubleshooting_en.md) | [Español](troubleshooting_es.md) | [中文](troubleshooting_zh.md) | [Français](troubleshooting_fr.md) | **Ausgewählt** |

[← Zurück zum README](../readme/README_de.md)

Beginne in der lokalen Anwendung mit „Проверить готовность“ („Bereitschaft prüfen“) und öffne „Подробнее“ („Details“) für die markierte Kategorie. Diese Prüfung kontaktiert weder Telegram noch KI, liest keine Profilinhalte und schreibt nichts in SQLite. Eine erfolgreiche Prüfung bestätigt nur lokale Voraussetzungen; die Verbindung testest du durch eine Nachricht an den laufenden Bot.

Oberflächentexte stehen in den [UI-Nachrichten](../../src/ui/windows/messages.json), Prüfungen und Fehlerkategorien in [`inspect.php`](../../src/ui/windows/inspect.php). Gib bei Supportanfragen Kategorie, Exit-Code, Betriebssystem und Startmethode an, aber keine Tokens oder privaten Profilinhalte.

## Anwendung lässt sich nicht öffnen

| Symptom | Maßnahme |
| --- | --- |
| „Файлы приложения отсутствуют или повреждены“ (Dateien fehlen oder sind beschädigt) | Vertrauenswürdiges Archiv in einen separaten Ordner neu entpacken; Struktur erhalten und nur private INI, Profil und `var/` übertragen. |
| „Нужен интерактивный терминал“ (interaktives Terminal erforderlich) | Aus einem normalen Terminal ohne Umleitung von Ein-/Ausgabe oder Fehlern starten. |
| „Среда PHP приложения неполна или устарела“ (PHP-Umgebung unvollständig/veraltet) | Passendes Archiv für Betriebssystem und Architektur prüfen und `runtime/` vollständig entpacken. |
| Start und Diagnose konnten nicht vorbereitet werden | Zugriff auf das temporäre Systemverzeichnis und Ausführungsrechte prüfen. |
| Windows blockiert das Skript | Geltende PowerShell-Richtlinie der Organisation prüfen und mit Administrator abstimmen. |
| macOS blockiert die heruntergeladene App | Herkunft des Archivs und Begründung in den macOS-Sicherheitseinstellungen prüfen. |

Bei beschädigten öffentlichen Dateien das **gesamte Archiv** wiederherstellen: Die [ausführbare Datei](../../src/launcher/main.go) prüft ein Manifest. Die [lokale Anleitung](local-gum_de.md#daten-und-updates) beschreibt eine Neuinstallation mit Datenerhalt.

Für eine Quellcode-Installation werden PHP `^8.5`, die Erweiterungen aus [`composer.json`](../../composer.json) und der generierte Composer-Autoloader benötigt. Fehlt `vendor/autoload.php`, installiere Abhängigkeiten nach `composer.lock`; Profil und SQLite müssen nicht geändert werden.

## Konfiguration oder Profil nicht bereit

- `config/bot.ini` anhand des [Beispiels](../../config/bot.example.ini) erstellen und prüfen, ob der Editor `.txt` angehängt hat.
- Anführungszeichen und INI-Schlüsselnamen prüfen. Vorfilterlisten benötigen wiederholte Schlüssel mit `[]`.
- Bot-Token, Telegram-ID des eigenen Kontos in `TELEGRAM_ALLOWED_USER_ID`, Modelladresse und Modellkennung setzen. Cloudmodelle benötigen zusätzlich einen API-Schlüssel.
- Beide Dateien `config/profile/local/candidate.md` und `config/profile/local/instructions.md` anlegen.
- Normale, nicht leere UTF-8-Dateien ohne Symlinks verwenden; jede höchstens 32.768 Byte und 16.000 Zeichen. Tabs und Zeilenumbrüche sind erlaubt, andere Steuerzeichen nicht.

Wenn die Bereitschaftsprüfung eine Datei akzeptiert, der Start sie aber ablehnt, prüfe Kodierung, Zeichenzahl und Inhalt: Das Menü kontrolliert nur Zugriff und Dateigröße. Details in der [Profilanleitung](../../config/profile/README_de.md) und [Konfigurationsreferenz](../reference/configuration_de.md).

Nach Änderungen lokale Überwachung neu starten. Auf dem Server liest der nächste `work-once` die neuen Dateien ein.

## ID lässt sich nicht ermitteln

Sende `/start` ohne Zusatztext und wähle erneut „Проверить сейчас“ („Jetzt prüfen“), oder führe `php bin/bot.php identify` aus der Quellcode-Installation aus. Eine weitergeleitete Nachricht oder `/start` mit Parametern zählt nicht. Die Suche erfolgt nur auf Anforderung und bestätigt keine Updates.

Ist `var/bot.lock` belegt, stoppe den anderen Polling-Prozess und versuche es erneut, ohne die Sperrdatei zu löschen. Aus den Quellen kannst du mit `php bin/bot.php webhook info` die Registrierung prüfen und sie mit `webhook delete` nach dem [Moduswechselverfahren](vds-webhook_de.md#modi-wechseln) entfernen. Wenn mehrere IDs erscheinen, rate nicht: Folge der [ID-Prüfung](telegram-bot_de.md#eigene-id-ermitteln). Für die erste ID-Suche sind Profil und KI-Verbindung nicht nötig.

## Authentifizierung, Zugriff und Empfangskonflikte

| Fehler | Ursache und Maßnahme |
| --- | --- |
| Bot API 401 / Authentifizierung abgelehnt | BotFather-Token prüfen und bei Neuausstellung in INI ersetzen. |
| Bot API 403 / Chat nicht erreichbar | Prüfen, ob der Chat begonnen, der Bot nicht blockiert und die eigene Telegram-ID angegeben ist. |
| Bot API 409 / Polling-Konflikt | Zweite Instanz stoppen und `php bin/bot.php webhook info` prüfen. |
| „Занята блокировка var/bot.lock.“ | Laufendes `run` oder `identify` beenden, Sperrdatei nicht löschen. |
| „Недоступна блокировка var/bot.lock.“ | Rechte für privates `var/` und Sperrdatei kontrollieren. |
| Webhook ausgewählt, lokaler Start verboten | Serverseitiges `work-once` verwenden oder vollständig zu Polling wechseln. |
| Bot API 429 | Zustellung beachtet ein gültiges `retry_after`; keine mehreren Empfänger starten und erlaubte Wiederholung abwarten. |

Der Bot empfängt Updates entweder durch `getUpdates` oder durch Webhook. Eine Änderung der INI entfernt keine Webhook-Registrierung. Für die Rückkehr zu Polling `php bin/bot.php webhook delete` ausführen (anstehende Updates bleiben erhalten), „Webhook не зарегистрирован.“ mit `webhook info` prüfen und dem [Verfahren](vds-webhook_de.md#modi-wechseln) folgen.

Der vom Menü verwendete Modus `identify --json` gibt absichtlich nur einen allgemeinen Fehler ohne ursprüngliche Exception zurück. Die Kategorie kann unbekannt bleiben; sie beweist keine bestimmte Netzwerk- oder Konfigurationsursache.

## Netzwerk- oder TLS-Fehler

Für Telegram DNS, HTTPS-Verbindung zu `api.telegram.org`, Systemzeit und vertrauenswürdige Zertifikate prüfen. Im Standalone-Archiv muss `runtime/cacert.pem` vorhanden sein. TLS-Prüfung nicht abschalten.

Für KI getrennt prüfen, ob Server und Modell laufen, die Adresse von der Anwendungsumgebung aus erreichbar ist und die Firewall den Port erlaubt. `127.0.0.1` innerhalb WSL, VM oder VDS bezeichnet keinen anderen Rechner. Im Modus `local` eine erreichbare private IPv4 verwenden.

Der KI-Transport nutzt keine Proxys und folgt keinen HTTP-Weiterleitungen. Gib die endgültige API-Basisadresse an. Die Grenzen setzt [`CurlAiTransport`](../../src/Analysis/Http/CurlAiTransport.php).

## Stelle gespeichert, aber keine Bewertung

1. Prüfen, ob die lokale Überwachung noch läuft. Auf dem Server cron, Benutzer, absolute Pfade zu PHP/Projekt und geschützte Befehlsausgabe prüfen.
2. Warteschlange und Antwortzeit berücksichtigen: Anfragen laufen nacheinander; auf dem Server beginnt die Arbeit laut cron.
3. Modell und API-Format prüfen. Basisadresse ohne `/chat/completions` angeben; die Anwendung ergänzt dies selbst.
4. Bei langsamen Modellen `AI_REQUEST_TIMEOUT_SECONDS` von `180` auf `300` oder `600` setzen und Verarbeitung neu starten. Bereich: 30–1800.
5. Erscheint „Не удалось обработать“, Ursache vor erneutem Test beseitigen: Die ursprüngliche Stelle bleibt in der Datenbank, eine endgültig fehlgeschlagene Aufgabe wird aber nicht automatisch fortgesetzt.

Ein erfolgreich empfangener Webhook bedeutet noch keine einsatzbereite KI: Der HTTP-Handler lädt weder Profil noch Modell. `work-once` muss seine eigene Initialisierung erfolgreich abschließen.

Um die Fehlerstelle zu finden, öffne **deine eigene** `var/vacancies.sqlite` in einem lokalen SQLite-Client **nur lesend** und frage Zustände ohne Ausschreibungstexte ab:

```sql
-- Nur lesend: letzte zehn Zustände ohne Stellenausschreibungstexte.
SELECT message_id, analysis_status, analysis_error, analysis_attempts,
       delivery_status, delivery_error, delivery_attempts
FROM analysis_jobs
ORDER BY message_id DESC
LIMIT 10;
```

Die Zustände speichert [`SqliteAnalysisRepository`](../../src/Storage/SqliteAnalysisRepository.php). Zähler und Zustände nicht manuell ändern. Die Datenbank nicht veröffentlichen; persönliche Kennungen vor dem Teilen von Diagnoseauszügen entfernen.

## Modellfehler oder ungültige Ausgabe

| Analysekategorie | Zu prüfen |
| --- | --- |
| `http_401`, `http_403` | Schlüssel und Zugriffsrechte für das Modell. |
| `http_404` | API-Basispfad und Modellkennung laut Anbieter. |
| `http_429`, `http_5xx` | Limits und Verfügbarkeit; diese Fehler können Wiederholungen auslösen. |
| `timeout` | Zeitlimit, Modellladezustand und Geschwindigkeit. |
| `invalid_response` | Chat-Completions-Hülle: `choices[0].message.content` als Zeichenfolge, ohne Toolaufrufe. |
| `invalid_output` | Genau ein JSON-Objekt mit Pflichtfeldern und zulässigen Werten, unverpackt oder in **einem** Markdown-Codeblock mit Kennzeichnung `json`, ohne zusätzlichen Text. |
| `response_size` | API-Antwort zu groß: höchstens 65.536 Byte. |
| `vacancy_size_or_encoding` | Gültiges UTF-8 des Ausschreibungstexts und 65.536-Byte-Grenze. |
| `transport` | Verbindung, TLS und Netzwerkzugriff auf den KI-Server. |

`http_5xx` meint konkrete Statuscodes wie `http_503`. Wiederholbarkeit hängt von der Ursache ab, nicht nur von verbleibenden Versuchen. Siehe [Schema und Zeichenfolgenregeln](../reference/analysis-result_de.md) und [Modelleinrichtung](ai-models_de.md).

## Bewertung in der DB, aber nicht im Chat

Prüfe, dass der Bot nicht blockiert und der Chat erreichbar ist. Der Versand des gespeicherten Ergebnisses besitzt eigene Wiederholungsversuche und ruft das Modell nicht erneut auf. Bei `delivery_status = failed` bleibt das Ergebnis in SQLite; eine separate Fehlermeldung wird nicht gesendet. Es gibt keinen Befehl zum manuellen erneuten Versand.

Die Startmeldung zur Analyse wird nur einmal versucht; ihr Fehlen bedeutet nicht, dass die Aufgabe verloren ist. Ein Absturz zwischen Versand und Speicherung der Bestätigung kann das Ergebnis doppelt zustellen. Siehe [Verarbeitungsablauf](message-processing_de.md).

## SQLite oder Webhook nicht erreichbar

Freien Platz, Les-/Schreibrechte der Datenbank und Zugriff auf `var/` für SQLite-Journale prüfen. Auf dem Server müssen CLI und FPM dieselbe Datenbank nutzen; Rechte beider Benutzer kontrollieren. Die Wartezeit auf die SQLite-Sperre ist auf 5 Sekunden begrenzt.

Webhook antwortet mit 503 bei nicht bereitem oder fehlerhaftem Speicher und mit 403 bei ungültigem Geheimnis. Prüfe `TELEGRAM_WEBHOOK_SECRET` in der privaten INI und die Registrierung über `php bin/bot.php webhook info`. Nach Geheimnisänderung mit `webhook set` und eigener HTTPS-Adresse neu registrieren. HTTP-Codes, Datenbankvorbereitung und Berechtigungen erklärt die [Serveranleitung](vds-webhook_de.md).

`var/`, Datenbank oder lokale Konfiguration nicht für einen „sauberen“ Neustart löschen. Bei Updates private Dateien und Warteschlangenzustand erhalten.
