# Telegram Vacancy Monitor

[![Source Code](https://img.shields.io/badge/source-yaleksandr89%2Ftelegram--vacancy--monitor-blue.svg?style=flat-square)](https://github.com/yaleksandr89/telegram-vacancy-monitor)
[![CI](https://img.shields.io/github/actions/workflow/status/yaleksandr89/telegram-vacancy-monitor/ci.yml?branch=master&style=flat-square&label=CI)](https://github.com/yaleksandr89/telegram-vacancy-monitor/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-%5E8.5-777BB4.svg?style=flat-square&logo=php&logoColor=white)](../../composer.json)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](../../LICENSE)

![Telegram Vacancy Monitor — personalisierte KI-Analyse von Stellenangeboten über Telegram](../images/telegram-vacancy-monitor-readme-cover.png)

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../../README.md) | [English](README_en.md) | [Español](README_es.md) | [中文](README_zh.md) | [Français](README_fr.md) | **Ausgewählt** |

Eine Anwendung zur persönlichen KI-gestützten Bewertung von Stellenangeboten. Sende eine Stellenausschreibung an deinen Telegram-Bot oder leite sie an ihn weiter. Die Anwendung speichert sie und schickt dir eine Bewertung, die deine Erfahrung, Vorlieben und Einschränkungen berücksichtigt.

Sie richtet sich an Menschen, die Stellenangebote aus verschiedenen Quellen sammeln und schneller entscheiden möchten, auf welche sich eine Bewerbung lohnt. Die Kriterien werden im Kandidatenprofil und in den Bewertungsanweisungen festgelegt. Der [Beispielleitfaden](../examples/vacancy-analysis_de.md) zeigt, wie diese erstellt werden. Über die Chat-Completions-Schnittstelle lässt sich ein lokales oder cloudbasiertes KI-Modell anbinden.

## Demonstration

Lokale Anwendung unter Windows · LM Studio · Modell `qwen/qwen3.5-9b`.

https://github.com/user-attachments/assets/afdc1b8d-c4bb-49d4-b82f-67436f11f69c

## Funktionen

- Stellenangebote über den Bot von dem Benutzer empfangen, dessen Telegram-ID in der Konfiguration hinterlegt ist.
- Stellenangebote in SQLite speichern und wiederholte Zustellungen abfangen. Die Anwendung erkennt auch das erneute Weiterleiten derselben Veröffentlichung, sofern Telegram die Quellkennungen übermittelt. [Speicherung, Cursor und Duplikaterkennung](../guides/message-processing_de.md).
- Persönliche Bewertung: „Bewerben“, „Grenzfall“ oder „Überspringen“, einschließlich Priorität, Übereinstimmungen, Risiken und offener Fragen.
- Die Bewertung vor dem Versand speichern; Analyse und Zustellung werden unabhängig voneinander erneut versucht.
- Die lokale Anwendung mit russisch- und englischsprachigen Menüs verwenden oder den Serverbetrieb nutzen.

## Funktionsweise

```mermaid
flowchart LR
    A[Senden oder weiterleiten] --> B[Bot-Chat]
    B --> C{Betriebsart}
    C -->|Lokal| D[Anwendung]
    C -->|Server| E[Webhook und cron]
    D --> F[(SQLite)]
    E --> F
    F --> G[KI-Analyse]
    P[Profil und Anweisungen] --> G
    G --> H[Ergebnis in Telegram]
```

Im lokalen Betrieb empfängt und analysiert die Anwendung Stellenangebote. Auf dem Server speichert der Webhook Nachrichten; cron startet deren Verarbeitung. [Zwischen den Betriebsarten wechseln](../guides/vds-webhook_de.md#modi-wechseln).

## Betriebsarten und Schnellstart

**Lokale Anwendung.** Nach der Veröffentlichung werden geprüfte Archive für Linux x86_64, Windows x64 und macOS ARM64 auf der Seite [Releases](https://github.com/yaleksandr89/telegram-vacancy-monitor/releases) bereitstehen. Bis zur Veröffentlichung des ersten Releases gibt es dort kein fertiges Archiv zum Herunterladen. Die [Startanleitung](../guides/local-gum_de.md) erklärt die Verwendung eines veröffentlichten Archivs: PHP und seine Komponenten sind enthalten, Gum zeigt das Menü an und unter Windows steuert PowerShell den Start.

**Server.** Für die Ausführung aus dem Quellcode werden PHP 8.5 (CLI und PHP-FPM), Composer, die Erweiterungen `curl`, `json`, `mbstring`, `PDO` und `pdo_sqlite`, HTTPS und cron benötigt. Die Daten liegen in SQLite; ein separater Datenbankserver ist nicht erforderlich. Einrichtung und Konfiguration beschreibt die [Serveranleitung](../guides/vds-webhook_de.md).

Konfiguriere anschließend die gewählte Installation:

1. [Erstelle einen Bot](../guides/telegram-bot_de.md) und speichere das von BotFather erhaltene Token gemäß der [Vorlage](../../config/bot.example.ini) in `config/bot.ini`.
2. Sende `/start` an den Bot, [ermittle deine Telegram-ID](../guides/telegram-bot_de.md#eigene-id-ermitteln) und trage sie in `TELEGRAM_ALLOWED_USER_ID` ein.
3. Erstelle dein [Profil und die Anweisungen](../../config/profile/README_de.md) und [verbinde ein KI-Modell](../guides/ai-models_de.md).
4. Starte die Überwachung über das Anwendungsmenü oder aktiviere den Serverbetrieb anhand der entsprechenden Anleitung.

Sende einen Ausschreibungstext an den Bot oder leite eine Veröffentlichung weiter. Der Bot bestätigt die Speicherung und schickt anschließend das Bewertungsergebnis.

## Analysebeispiele

- [Go-Entwickler](../examples/go-developer_de.md): Kandidatenprofil, Bewertungsregeln und eine technische Stellenausschreibung.
- [Produktanalyst](../examples/analyst_de.md): Kandidatenprofil, Anforderungen an analytische Fähigkeiten und Ausschreibungstext.

## Dokumentation

### Start und Konfiguration

- [Lokale Anwendung](../guides/local-gum_de.md): Installation und Start unter Linux, Windows und macOS.
- [Anwendungsoberfläche](../guides/local-interface_de.md): Hauptmenü, Hilfe und Konfigurationsprüfung.
- [Serverbetrieb](../guides/vds-webhook_de.md): Einrichtung von Webhook, PHP-FPM und cron.
- [Telegram-Bot](../guides/telegram-bot_de.md): Bot erstellen, ID ermitteln und Stellenangebote einreichen.
- [KI-Modelle](../guides/ai-models_de.md): ein lokales oder cloudbasiertes Modell anbinden.
- [Kandidatenprofil](../../config/profile/README_de.md): Profil und Bewertungsanweisungen erstellen.

### Verarbeitung und Referenzen

- [Verarbeitung von Stellenangeboten](../guides/message-processing_de.md): Speicherung, Cursor, Duplikate und Wiederaufnahme der Verarbeitung.
- [Konfigurationsreferenz](../reference/configuration_de.md): Anwendungseinstellungen mit Beispielen.
- [Bewertungsformat](../reference/analysis-result_de.md): Modell-JSON und Telegram-Benachrichtigungen.
- [Stellenbeispiele](../examples/vacancy-analysis_de.md): technische und analytische Positionen.
- [Fehlerbehebung](../guides/troubleshooting_de.md): Diagnose und häufige Fehler.

## Feedback

- Melde Fehler oder schlage Verbesserungen über [GitHub Issues](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) vor.
- Tausche dich über die Nutzung der Anwendung in [GitHub Discussions](https://github.com/yaleksandr89/telegram-vacancy-monitor/discussions) aus.

---

<p align="center">
  Wenn dir das Projekt geholfen hat, gib ihm einen Stern auf GitHub, damit andere es leichter finden. 🤘
</p>
