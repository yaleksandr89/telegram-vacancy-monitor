# Lokale Anwendung

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](local-gum.md) | [English](local-gum_en.md) | [Español](local-gum_es.md) | [中文](local-gum_zh.md) | [Français](local-gum_fr.md) | **Ausgewählt** |

[← Zurück zum README](../readme/README_de.md)

Sende dem Bot den Text einer Stelle oder leite eine Ausschreibung aus einem anderen Chat oder Kanal weiter. Die Anwendung speichert die Stelle, bewertet sie anhand deines Profils und sendet das Ergebnis zurück. Während der Überwachung muss das Anwendungsfenster geöffnet bleiben.

## Archiv auswählen

Die Standalone-Archive für Linux x86_64, Windows x64 und macOS ARM64 stehen auf der Seite [Releases](https://github.com/yaleksandr89/telegram-vacancy-monitor/releases) bereit. Wähle das Archiv für dein Betriebssystem aus der folgenden Tabelle.

| Plattform | Archivname | Voraussetzungen am Computer |
| --- | --- | --- |
| Linux x86_64 | `telegram-vacancy-monitor-linux-x86_64.tar.gz` | Interaktives Terminal, Bash und `dirname` |
| Windows x64 | `telegram-vacancy-monitor-windows-x64.zip` | Windows 10/11 und integriertes Windows PowerShell 5.1 |
| macOS ARM64 | `telegram-vacancy-monitor-macos-arm64.tar.gz` | macOS 14+ auf Apple Silicon und interaktives Terminal |

Das Archiv enthält einen Ordner `telegram-vacancy-monitor/` mit ausführbarer Datei, PHP, Gum, Konfigurationsbeispielen, `LICENSE` und zwei Anleitungen: `docs/guides/local-gum.md` und `docs/guides/vds-webhook.md`. Die restliche Dokumentation liegt im Repository. Unter Windows startet PowerShell das Gum-Menü. PHP, Composer und Gum müssen nicht separat installiert werden. Der Modellserver wird getrennt gestartet.

Erhalte die gesamte Verzeichnisstruktur. Linux nutzt mitgelieferte Bibliotheken und den Loader; macOS zusätzlich Systembibliotheken. Das ARM64-Archiv ist für Apple Silicon gedacht, das Windows-x64-Archiv für x64-Windows. macOS kann eine heruntergeladene Anwendung blockieren: Prüfe die Herkunft des Archivs und die Meldung in den Sicherheitseinstellungen, statt den gesamten Systemschutz auszuschalten.

Der [Packer](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/build/common/package.py) bestimmt den Archivinhalt. Vor dem Start prüft die ausführbare Datei öffentliche Dateien anhand von `SHA256SUMS.json`. Speichere Einstellungen in den unten genannten Dateien.

## Entpacken und starten

Entpacke das Archiv in einen privaten Ordner mit Schreibrechten. Die Befehle werden in dem Verzeichnis ausgeführt, in dem das heruntergeladene Archiv liegt.

Linux:

```bash
# Archiv entpacken und Anwendung aus dem erstellten Ordner starten.
tar -xzf telegram-vacancy-monitor-linux-x86_64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Windows: ZIP entpacken und `telegram-vacancy-monitor/vacancy-monitor.exe` per **Doppelklick** öffnen. Alternativ mit PowerShell starten:

```powershell
# In einen separaten Ordner entpacken und Anwendung starten.
Expand-Archive -LiteralPath .\telegram-vacancy-monitor-windows-x64.zip -DestinationPath .\vacancy-monitor-app
Set-Location -LiteralPath .\vacancy-monitor-app\telegram-vacancy-monitor
.\vacancy-monitor.exe
```

macOS auf Apple Silicon:

```bash
# ARM64-Build entpacken und starten.
tar -xzf telegram-vacancy-monitor-macos-arm64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Später kannst du unter Windows `vacancy-monitor.exe` erneut doppelklicken. Unter Linux und macOS im Anwendungsordner `./vacancy-monitor` ausführen. Leerzeichen in Pfaden sind erlaubt.

## Darstellung der Anwendung

Hauptmenü, Hilfe und Konfigurationsmeldungen zeigt die [Oberflächenübersicht](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-interface_de.md). Die tatsächliche Oberfläche unterstützt nur Russisch und Englisch, nicht alle Sprachen der Dokumentation.

## Private Konfiguration erstellen

Alle folgenden Pfade beziehen sich auf den Ordner mit der ausführbaren Datei. Kopiere das [Beispiel](../../config/bot.example.ini) nach `config/bot.ini`, falls die Datei noch nicht existiert.

Linux/macOS:

```bash
# Konfiguration anlegen, ohne eine vorhandene Datei zu überschreiben.
cp -n config/bot.example.ini config/bot.ini

# Profilverzeichnis vorbereiten und Beispielanweisungen kopieren.
mkdir -p config/profile/local
cp -n config/profile/instructions.example.md config/profile/local/instructions.md
```

Windows PowerShell:

```powershell
# Vorhandene Konfiguration beim erneuten Einrichten nicht überschreiben.
if (-not (Test-Path -LiteralPath .\config\bot.ini)) {
    Copy-Item -LiteralPath .\config\bot.example.ini -Destination .\config\bot.ini
}
# Verzeichnis für private Profildateien vorbereiten.
New-Item -ItemType Directory -Path .\config\profile\local -Force | Out-Null
# Beispielanweisungen nur kopieren, wenn noch keine eigene Datei vorhanden ist.
if (-not (Test-Path -LiteralPath .\config\profile\local\instructions.md)) {
    Copy-Item -LiteralPath .\config\profile\instructions.example.md -Destination .\config\profile\local\instructions.md
}
```

Bearbeite `config/bot.ini` in einem Texteditor. Gib Geheimnisse nicht als Terminalbefehle ein.

1. Öffne [BotFather](https://t.me/BotFather), erstelle mit `/newbot` einen Bot und trage das Token in `TELEGRAM_BOT_TOKEN` ein.
2. Sende dem Bot `/start` als einzelne, direkt geschriebene Nachricht ohne Weiterleitung oder Zusatztext.
3. Wähle im Programm „Узнать Telegram User ID“ („Telegram-Benutzer-ID ermitteln“) und anschließend „Проверить сейчас“ („Jetzt prüfen“). Hierfür reichen Bot-Token und `polling`; Profil und KI-Konfiguration sind noch nicht erforderlich.
4. Trage **deine eigene numerische Telegram-ID** in `TELEGRAM_ALLOWED_USER_ID` ein. Werden mehrere IDs gefunden und lässt sich deine nicht bestimmen, rate nicht: Befolge das [Verfahren in der Bot-Anleitung](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot_de.md#eigene-id-ermitteln).
5. Behalte `TELEGRAM_RECEIVE_MODE = "polling"` bei.

Die ID-Suche erfolgt nur auf Anforderung und bestätigt keine ausstehenden Updates. `identify` nutzt dieselbe nicht blockierende Sperre `var/bot.lock` wie die Überwachung: Beende diese zuerst, ohne die Sperrdatei zu löschen. Ist ein Webhook registriert, folge dem [sicheren Wechsel zum lokalen Modus](vds-webhook_de.md#modi-wechseln). Weitere Einzelheiten stehen in der [Online-Bot-Anleitung](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot_de.md).

## Profil und Modell vorbereiten

Erstelle im Editor `config/profile/local/candidate.md` für Erfahrung, Kenntnisse, gewünschte Tätigkeit und Einschränkungen. In `config/profile/local/instructions.md` legst du die Bewertungsregeln fest. Beide Dateien müssen existieren, nicht leer sein, gültiges UTF-8 enthalten und dürfen jeweils höchstens 32 KiB und 16.000 Zeichen umfassen. Siehe [Profilanleitung](../../config/profile/README_de.md).

Starte einen Modellserver mit Chat Completions. Läuft er auf demselben Rechner, sind etwa folgende Einstellungen möglich:

```ini
; Lokales API-Beispiel: eigene Modellkennung eintragen.
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

Ersetze `model-id` durch die API-Kennung des geladenen Modells und die Adresse durch deinen Endpunkt. Die Anwendung ergänzt `/chat/completions` an `AI_BASE_URL`. Der Server muss nicht gestreamte Anfragen annehmen und schema-konformes JSON in `message.content` zurückgeben. Prüfe Server und Modell mit einer Teststelle.

Im Modus `local` sind `localhost`, `127.0.0.0/8`, `[::1]` sowie private IPv4 in `10.0.0.0/8`, `172.16.0.0/12` oder `192.168.0.0/16` zulässig. Bei einem Modell auf einem anderen Rechner verwende dessen private IPv4 und begrenze den Zugriff auf das nötige Netz. Ein lokaler Hostname statt einer IP besteht die Prüfung nicht. `127.0.0.1` bezeichnet den Rechner, auf dem die Anwendung läuft.

Für Cloudmodelle setze `AI_PROVIDER_LOCATION = "cloud"`, eine HTTPS-Adresse, die Modellkennung und `AI_API_KEY`.

Weitere Informationen: [Modelle anbinden](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models_de.md) und [INI-Referenz](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/configuration_de.md).

## Startvoraussetzungen prüfen und Überwachung beginnen

Wähle „Проверить готовность“ („Bereitschaft prüfen“). Unter „Подробнее“ („Details“) werden Laufzeitumgebung, Einstellungen, Profil und Speicher geprüft. Danach kannst du eine [Teststelle](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/vacancy-analysis_de.md) senden und den Ablauf überprüfen; diese Beispielseite liegt nicht im Standalone-Archiv.

Nach der Einrichtung wähle „Запустить мониторинг“ („Überwachung starten“) und sende dem Bot einen kurzen Ausschreibungstext. Normalerweise erscheinen `Вакансия сохранена.`, eine Startmeldung und anschließend die Bewertung. Sie enthält Urteil, Priorität, Begründung, Bedingungen, Übereinstimmungen, Risiken und Fragen. Die Quelle wird gesondert markiert; ein Link aus dem Text ist nicht automatisch verifiziert.

Modellanfragen laufen nacheinander. Für langsame Modelle kannst du `AI_REQUEST_TIMEOUT_SECONDS` auf `300` oder `600` erhöhen und die Überwachung neu starten. Zulässig sind 30–1800 Sekunden pro Anfrage. `AI_MAX_ATTEMPTS` erlaubt 1–5 Versuche einschließlich des ersten, getrennt für Analyse und Zustellung. Dauerhafte Fehler können den Schritt sofort beenden.

Die Bewertung wird vor dem Versand gespeichert. Eine erneute Nachrichtenzustellung erfordert daher keine weitere Analyse. Siehe [Duplikate und Wiederaufnahme](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/message-processing_de.md).

## Menü und Beenden

| Aktion | Bedeutung |
| --- | --- |
| „Запустить мониторинг“ | Nachrichten empfangen und Warteschlange bearbeiten, solange der Prozess läuft. |
| „Проверить готовность“ | Lokale Diagnose ohne API-Aufruf. |
| „Узнать Telegram User ID“ | Manuelle Suche nach Absendern von `/start`. |
| „Справка“ | Schnellstart, Verarbeitung, Datenschutz, Modell und Server. |
| „Язык“ | Russisch oder Englisch für das Menü bis zum Schließen der Anwendung. |
| „Выход“ | Anwendung verlassen. |

Mit Pfeiltasten und Enter navigieren. Esc führt zurück, lässt das Hauptmenü aber geöffnet. Strg+C beendet Überwachung und Anwendung. Nach Abschluss eines Befehls oder einem Fehler bietet das Menü Wiederholen, Zurück oder Beenden an; ein automatischer Neustart findet nicht statt. Die Antwortsprache des Modells wird unabhängig vom Menü in `instructions.md` festgelegt.

## Daten und Updates

Persönliche Dateien werden bei der Einrichtung erstellt und sind nicht im Archiv enthalten. Beispiele: [`config/bot.example.ini`](../../config/bot.example.ini), [Go-Profil](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_de.md#kandidatenprofil), [Analystenprofil](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_de.md#kandidatenprofil) und [Modellanweisungen](../../config/profile/instructions.example.md).

| Pfad | Aufzubewahren |
| --- | --- |
| `config/bot.ini` | Bot-Token, Telegram-ID, KI-Verbindung und Regeln. |
| `config/profile/local/` | Profil, Anweisungen und private Sicherungen. |
| `var/` | SQLite, Warteschlange und Bearbeitungszustand. |

Vor einem Update beende die Anwendung und bestätige, dass der Prozess beendet ist. Sichere diese drei Pfade privat. Entpacke das neue Archiv in einen separaten Ordner, übertrage private Dateien und den vollständigen Ordner `var/` an die entsprechenden Stellen, gleiche dein INI mit dem neuen Beispiel ab und starte nur die neue Kopie. Nicht über eine laufende Installation entpacken und keine bestehende Datenbank durch eine leere ersetzen.

Nach Änderungen an Konfiguration, Profil oder Anweisungen Überwachung neu starten. Bereits gespeicherte Bewertungen werden nicht automatisch neu berechnet.

## Wenn etwas nicht funktioniert

- **Keine ID:** Sende `/start`, wähle erneut „Проверить сейчас“ und prüfe Token, Webhook-Registrierung und `var/bot.lock`.
- **Konfigurationsfehler:** Prüfe `config/bot.ini`, beide Profildateien und die Bereitschaftsdetails.
- **Autorisierungsfehler:** BotFather-Token prüfen, INI aktualisieren und Anwendung neu starten.
- **Netzwerk- oder TLS-Fehler:** Telegram-Zugang, DNS, Systemzeit und Zertifikate prüfen. TLS-Prüfung nicht abschalten.
- **Speicher nicht verfügbar:** Freien Platz und Berechtigungen des Anwendungsordners, von `var/` und SQLite prüfen. Datenbank oder Sperrdatei nicht zur Fehlerumgehung löschen.
- **Gespeichert, aber keine Bewertung:** Sicherstellen, dass Überwachung und Modellserver laufen und gültiges JSON zurückkommt.
- **Beschädigte öffentliche Dateien:** Vertrauenswürdiges Archiv erneut in einen getrennten Ordner entpacken und nur persönliche Daten übertragen.

Weitere Hilfe: [Fehlerbehebung](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/troubleshooting_de.md) und [Ergebnisformat](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result_de.md).
