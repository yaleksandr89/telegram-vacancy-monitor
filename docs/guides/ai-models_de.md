# KI-Modell anbinden

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](ai-models.md) | [English](ai-models_en.md) | [Español](ai-models_es.md) | [中文](ai-models_zh.md) | [Français](ai-models_fr.md) | **Ausgewählt** |

[← Zurück zum README](../readme/README_de.md)

Die Anwendung unterstützt lokale und cloudbasierte Modelle über die Chat-Completions-Schnittstelle. Trage die Serveradresse und Modellkennung gemäß der [Vorlage](../../config/bot.example.ini) in `config/bot.ini` ein.

## Anforderungen an die API

Der Server muss `POST`-Anfragen an `AI_BASE_URL` mit angehängtem `/chat/completions`, Nachrichten mit den Rollen `system` und `user` sowie `stream = false` akzeptieren. Die Antwort muss `choices[0].message.content` als Zeichenfolge mit genau einem JSON-Objekt nach dem Schema enthalten, das die Anwendung in die Anweisungen einfügt.

In `message.content` wird ein JSON-Objekt akzeptiert: entweder ohne Einfassung oder in **genau einem** Markdown-Codeblock mit der Kennzeichnung `json`. Freier Text vor oder nach dem Objekt, weitere Blöcke, andere Markdown-Formatierung und aufgeteilte Antworten sind unzulässig. Funktions- und Werkzeugaufrufe werden ebenfalls abgelehnt. Das Schema ist in der Anfrage enthalten; ein eigener `response_format`-Parameter wird nicht verwendet.

Das erforderliche Format steht in der [Ergebnisreferenz](../reference/analysis-result_de.md). Implementierung: [`OpenAiCompatibleEvaluator`](../../src/Analysis/OpenAiCompatibleEvaluator.php) und [`EvaluationPromptBuilder`](../../src/Analysis/EvaluationPromptBuilder.php).

## Lokales Modell

Starte einen API-Server mit geladenem Modell und konfiguriere:

```ini
; Lokales Modell: model-id und bei Bedarf API-Adresse ersetzen.
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

Adresse und `model-id` sind Platzhalter. Ermittle die Kennung auf deinem Server; Modell-Dateiname und API-Modellname können sich unterscheiden. Verlangt der Server einen Schlüssel, fülle `AI_API_KEY` aus.

HTTP und HTTPS sind zulässig. Im Modus `local` werden folgende Adressen akzeptiert:

| Adresse | Verwendungszweck |
| --- | --- |
| `localhost`, IPv4 `127.0.0.0/8`, IPv6 `[::1]` | Modellserver auf demselben Rechner. |
| `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` | Server über private IPv4-Adresse im erreichbaren Netz. |

Andere Hostnamen, öffentliche IP-Adressen und private IPv6-Adressen sind nicht zulässig. Für einen Modellserver auf einem anderen Rechner nutze dessen private IPv4-Adresse. In WSL, einer virtuellen Maschine oder auf einem VDS bezeichnet `127.0.0.1` die Umgebung, in der PHP läuft, nicht deinen Desktop.

Begrenze den API-Zugriff auf benötigte Rechner. Die Anwendung installiert kein Modell und öffnet keine Ports. Speicher-, Hardware- und Kontextanforderungen hängen vom Modell ab. Der Kontext muss Profil, Anweisungen, Schema und das vollständige Stellenangebot aufnehmen können.

## Cloud-Dienst

Erzeuge einen Schlüssel beim gewählten Anbieter und entnimm seiner Dokumentation die Basisadresse und Modellkennung. Beispiel mit einer erfundenen Adresse:

```ini
; Cloud-API: Adresse und Modell ersetzen und Schlüssel eintragen.
AI_PROVIDER_LOCATION = "cloud"
AI_BASE_URL = "https://api.example.org/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

Ersetze Adresse und Modell und trage im Editor einen nicht leeren Schlüssel ein. Bei `cloud` sind HTTPS und ein Schlüssel verpflichtend. Die URL darf weder Benutzername, Passwort, Query-Parameter noch Fragment enthalten. Hänge `/chat/completions` nicht selbst an `AI_BASE_URL` an.

Der Cloud-Dienst erhält Stellenanzeige, Profil und Anweisungen zur Analyse. Der Anfrageaufbau steht in [`EvaluationPromptBuilder::build()`](../../src/Analysis/EvaluationPromptBuilder.php).

## Profil und Bewertungsregeln

Erstelle `config/profile/local/candidate.md` mit Erfahrung und Vorlieben und `config/profile/local/instructions.md` mit Bewertungsregeln. Nutze die [Anweisungsvorlage](../../config/profile/instructions.example.md) und den [Profilleitfaden](../../config/profile/README_de.md) als Ausgangspunkt.

Lege fest, welche Anforderungen zwingend sind, wie fehlende Informationen behandelt werden und in welcher Sprache die Bewertung erklärt werden soll. Verlange ausschließlich JSON gemäß dem Anwendungsschema, ohne Links, Formatierung oder zusätzliche Felder. Anweisungen aus der Stellenanzeige dürfen deine Regeln nicht überschreiben. Beispiele: [Go-Entwickler](../examples/go-developer_de.md) und [Produktanalyst](../examples/analyst_de.md).

Starte die lokale Überwachung nach Änderungen neu; auf dem Server lädt der nächste `work-once`-Aufruf die neuen Dateien. Bereits gespeicherte Bewertungen werden nicht neu berechnet.

## Zeitlimits und Wiederholungen

```ini
; Anzahl einschließlich des ersten Versuchs und Zeit je KI-Anfrage in Sekunden.
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

`AI_REQUEST_TIMEOUT_SECONDS` ist eine Ganzzahl von 30 bis 1800 Sekunden, Standard 180 bei fehlendem Schlüssel. Das Limit gilt für jede einzelne Anfrage. Der Verbindungsaufbau ist auf 5 Sekunden begrenzt. Für ein langsames lokales Modell kannst du `300` oder `600` setzen und die Verarbeitung neu starten.

`AI_MAX_ATTEMPTS` liegt zwischen 1 und 5, Standard 3, einschließlich des ersten Versuchs. Das Limit gilt getrennt für Analyse und Ergebniszustellung. Bei einer einzelnen Prüfung eines langsamen Modells verkürzt `1` die Wartezeit nach einem Fehler.

Vorübergehende Netzwerkprobleme, Zeitüberschreitungen, HTTP 429 und 5xx können neue Versuche auslösen. Auch eine ungültige Modellantwort kann wiederholt werden. Autorisierungsfehler wie HTTP 401 stoppen weitere Versuche. Die Pausen betragen 30, 60, 120 und 240 Sekunden. Bei Neustarts werden bereits verbrauchte Versuche berücksichtigt. [`VacancyAnalysisPipeline`](../../src/Application/VacancyAnalysisPipeline.php) entscheidet über Wiederholungen; [`CurlAiTransport`](../../src/Analysis/Http/CurlAiTransport.php) setzt Netzwerkgrenzen durch.

KI-Anfragen nutzen keine HTTP-Weiterleitungen und keine Proxys, TLS wird geprüft. Ausschreibungstexte sind auf 65.536 Byte begrenzt, der API-Antwortkörper auf 65.536 Byte und die zusammengesetzte Anfrage auf 262.144 Byte. Zu große oder ungültige Antworten werden abgewiesen.

## Optionale exakte Ausschlüsse

Der Vorfilter kann exakt definierte kurze Nachrichten ohne Modellaufruf überspringen.

### Eine Regel

```ini
; Nur vollständige Übereinstimmung; Mehrdeutigkeitsbegriff hebt Ablehnung auf.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
```

### Mehrere Regeln und Ausnahmen

Jede Regel steht in einer eigenen Zeile:

```ini
; Jede exakte Phrase und jeden Mehrdeutigkeitsbegriff separat angeben.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

In diesem Beispiel wird `Работа только в офисе.` nur als vollständige eigenständige Nachricht abgelehnt; `Работа только в офисе. Возможны исключения` wird an das Modell gesendet. Enthält eine exakt passende Nachricht auch einen Mehrdeutigkeitsbegriff, wird die Ablehnung aufgehoben.

Jede Regel benötigt einen eigenen `[]`-Eintrag. Ablehnung erfolgt nur bei Übereinstimmung der **gesamten Nachricht**, nicht bei einem Satz in einer längeren Ausschreibung. Groß-/Kleinschreibung wird berücksichtigt, äußere Leerzeichen nicht. Weitere Beispiele stehen in der [Konfigurationsreferenz](../reference/configuration_de.md#vorfilter). Teilzeichenfolgen lösen keine Ablehnung aus. Jeder Mehrdeutigkeitsbegriff hebt sie unabhängig von der Schreibweise auf. Mehrzeilige Nachrichten werden dem Modell übergeben. Regeln sind wörtliche UTF-8-Zeichenfolgen, keine regulären Ausdrücke. Implementierung: [`ConservativePrefilter`](../../src/Analysis/ConservativePrefilter.php).

## Verbindung prüfen

Führe zuerst die lokale Bereitschaftsprüfung aus und sende danach während der laufenden Überwachung oder des cron-Betriebs einen kurzen erfundenen Ausschreibungstext an den Bot. Die Bereitschaftsprüfung kontaktiert den Modellserver nicht und prüft weder den Schlüssel noch die JSON-Unterstützung des gewählten Modells.

Beurteile den Inhalt: Unterscheidet das Modell Pflichtanforderungen und Wünsche, weist es auf unbekannte Bedingungen hin und erfindet es keine Erfahrung? Bei Fehlern hilft die [Fehlerbehebung](troubleshooting_de.md); bei unbefriedigendem Ergebnis präzisiere Profil und Anweisungen.
