# Kandidatenprofil und Anweisungen

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../../../config/profile/README.md) | [English](../en/profile.md) | [Español](../es/profile.md) | [中文](../zh/profile.md) | [Français](../fr/profile.md) | **Ausgewählt** |

[← Zurück zum README](README.md)

Für die persönliche Bewertung werden zwei private Markdown-Dateien benötigt. Sie werden beim Beginn der Verarbeitung aus dem festen Verzeichnis `config/profile/local/` relativ zum Anwendungsverzeichnis geladen.

| Datei | Inhalt |
| --- | --- |
| `config/profile/local/candidate.md` | Erfahrung, Fähigkeiten, gewünschte Aufgaben, Arbeitsmodell und Einschränkungen des Kandidaten |
| `config/profile/local/instructions.md` | Regeln zum Abgleich mit dem Angebot, zur Wahl des Urteils und der Priorität sowie zur Sprache der Erklärungen |

Erstelle das Verzeichnis `local/`, schreibe `candidate.md` in einem Texteditor und kopiere das [Anweisungsbeispiel](../../../config/profile/instructions.example.md) nach `local/instructions.md`. Einfaches Markdown genügt; bestimmte Überschriften oder Felder sind nicht vorgeschrieben. Ändere nicht die öffentliche Vorlage anstelle deiner privaten Kopie.

## Was hineingehört

Beschreibe Fähigkeiten und Erfahrung, gewünschte Aufgaben, Arbeitsmodell, Standort, Gehaltsvorstellungen und wichtige Einschränkungen. Als Orientierung dienen die Beispiele für [Go-Entwickler](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/de/go-developer.md#kandidatenprofil) und [Analysten](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/de/analyst.md#kandidatenprofil).

Lege in den Anweisungen fest, welche Anforderungen zwingend sind, wann eine hohe Priorität vergeben werden soll und welche Bedingungen geklärt werden müssen. Das [Anweisungsbeispiel](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/de/go-developer.md#bewertungsanweisungen) zeigt eine mögliche Struktur. Hier wird auch die Antwortsprache des Modells festgelegt. Die mitgelieferten Beispielanweisungen verlangen Erklärungen auf Russisch; die Übersetzung dieses Leitfadens ändert das nicht.

Die Anwendung ergänzt die Anweisungen um das JSON-Schema und übermittelt Profil und Stellenausschreibung getrennt. Behalte die Vorgabe bei, genau ein JSON-Objekt ohne zusätzliche Felder, Links oder Formatierung zurückzugeben. Der Ausschreibungstext ist eine Datenquelle für die Analyse und keine Anweisung an das Modell. [`EvaluationPromptBuilder`](../../../src/Analysis/EvaluationPromptBuilder.php) beschreibt den Aufbau der Anfrage; die Antwortfelder stehen in der [Online-Referenz](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/de/analysis-result.md).

Kopierbare Beispiele: [Profil und Anweisungen für einen Go-Entwickler](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/de/go-developer.md) und für einen [Produktanalysten](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/de/analyst.md).

## Dateibeschränkungen

- Beide Dateien sind erforderlich, nicht leer und lesbar; symbolische Links sind nicht zulässig.
- Die Codierung muss gültiges UTF-8 sein.
- Jede Datei darf höchstens 32.768 Byte (32 KiB) ursprünglichen Text und 16.000 Zeichen enthalten, einschließlich Leerzeichen und Zeilenumbrüchen.
- Tabulatoren und normale Zeilenumbrüche sind erlaubt; andere Steuerzeichen sind verboten.

Die Prüfungen übernehmen [`CandidateProfileLoader`](../../../src/Analysis/CandidateProfileLoader.php), [`CandidateProfile`](../../../src/Analysis/CandidateProfile.php), [`EvaluationInstructionsLoader`](../../../src/Analysis/EvaluationInstructionsLoader.php) und [`EvaluationInstructions`](../../../src/Analysis/EvaluationInstructions.php). Fehlt eine Datei oder ist sie ungültig, beendet sich die CLI vor dem Empfang und der Verarbeitung von Nachrichten. Der Befehl `identify` benötigt diese Dateien nicht. Im Serverbetrieb kann der Webhook Nachrichten auch ohne fertiges Profil speichern; `work-once` kann die Warteschlange ohne Profil jedoch nicht verarbeiten.

## Änderungen neu laden

Beende nach dem Bearbeiten die lokale Überwachung und starte sie erneut. Auf dem Server lädt der nächste `work-once`-Aufruf die neuen Dateien; ein bereits laufender Prozess verwendet weiterhin seine zuvor geladenen Daten. Gespeicherte Bewertungen werden nicht automatisch neu berechnet.

Das Verzeichnis `config/profile/local/` ist von Git ausgeschlossen. Bewahre die Profildateien bei Updates zusammen mit `config/bot.ini` und den Daten in `var/` auf. Die Anbindung von Modellen erklärt der [Leitfaden](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/de/ai-models.md).

Modelladresse, Schlüssel, Anfrage-Timeout und Anzahl der Versuche werden gemäß der [Konfigurationsvorlage](../../../config/bot.example.ini) in `config/bot.ini` festgelegt. Den Start beschreiben die Anleitungen für [lokalen Betrieb](local-gum.md) und [Serverbetrieb](vds-webhook.md).
