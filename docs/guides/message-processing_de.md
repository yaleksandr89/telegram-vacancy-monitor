# Empfang und Verarbeitung von Stellenangeboten

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](message-processing.md) | [English](message-processing_en.md) | [Español](message-processing_es.md) | [中文](message-processing_zh.md) | [Français](message-processing_fr.md) | **Ausgewählt** |

[← Zurück zum README](../readme/README_de.md)

Wenn du ein Stellenangebot an den Bot sendest oder weiterleitest, übermittelt Telegram der Anwendung ein Update. Die Anwendung speichert den Text und legt einen Bewertungsauftrag an. Nach der Analyse wird das Ergebnis in denselben Chat gesendet.

## Gespeicherte Daten

Für jedes Stellenangebot speichert SQLite den Text, Nachrichtenkennungen und, soweit vorhanden, Angaben zur ursprünglichen weitergeleiteten Veröffentlichung. Bewertungs- und Zustellstatus werden getrennt gespeichert.

Im [Datenbankschema](../../src/Storage/SqliteSchema.php) entsprechen dem die Tabellen `messages` und `analysis_jobs`.

## Wozu der Cursor dient

Im lokalen Betrieb empfängt die Anwendung Updates über `getUpdates`. Jedes Update hat eine numerische `update_id`. Der **Cursor** speichert die nächste Position nach dem letzten zuverlässig verarbeiteten und gespeicherten Update. Vor jeder Anfrage prüft die Anwendung, ob dieser Wert noch vertrauenswürdig ist.

Beispiel:

1. Die Anwendung erhält Update `104` mit einem neuen Stellenangebot.
2. Sie schreibt es nach SQLite, erstellt einen Auftrag und speichert den nächsten Cursor `105`.
3. Die nächste Telegram-Anfrage beginnt mit `offset = 105`: Update `104` muss nicht erneut empfangen werden.

Das Speichern des Stellenangebots und das Weiterstellen des Cursors erfolgen gemeinsam. Scheitert das Schreiben, bleibt der Cursor unverändert und Telegram kann das Update erneut senden. Ein bereits gespeichertes Update erzeugt keine zweite Kopie.

### Bedeutung der Cursor-Felder

Die Tabelle `cursor` enthält einen Datensatz für den lokalen Empfang:

| Spalte | Beispiel | Bedeutung |
| --- | --- | --- |
| `name` | `main` | Fester Cursor-Name, nicht der Name eines Bots oder Chats. |
| `next_offset` | `105` | Position nach der letzten gespeicherten `update_id`; wird vor Verwendung auf Aktualität geprüft. |
| `last_handled_at` | `1795000000` | Unix-Zeitpunkt, zu dem die Verarbeitung mit dem Cursor gespeichert wurde. Nach Migration einer alten DB kann der Wert `NULL` sein. |

**`next_offset` wird aus `update_id` berechnet, nicht aus der Nachrichtennummer.** Nach Update `104` wird `105` gespeichert. Innerhalb einer aktuellen Sequenz verringert sich der Cursor nicht; leere `getUpdates`-Antworten aktualisieren `last_handled_at` nicht. Zeitpunkt und Cursor werden gegebenenfalls mit Nachricht und Auftrag in derselben Transaktion gespeichert.

### Wiederaufnahme nach längerer Inaktivität

Telegram kann nach mindestens einer Woche ohne neue Updates wieder kleinere `update_id`-Werte vergeben. Wenn dann der alte hohe `offset` gesendet wird, könnten beim Telegram Bot API noch unbearbeitete Updates mit kleineren IDs bestätigt werden.

Deshalb sendet die Anwendung vor **jeder** ausgehenden `getUpdates`-Anfrage `offset = 0`, falls `last_handled_at` fehlt, ungültig ist, in der Zukunft liegt oder älter als fünf Tage ist. Der alte Cursor wird nicht vorsorglich gelöscht. Das erste erfolgreich gespeicherte Update legt die neue Position fest, auch wenn seine ID kleiner ist als zuvor. Die SQLite-Eindeutigkeitsregeln verhindern weiterhin doppelte Angebote und Aufträge.

Enthält eine neue Antwort IDs unter dem angeforderten `offset` oder kommen Updates in falscher Reihenfolge, wird der Empfang ohne Cursor-Fortschritt abgebrochen. Scheitert das Schreiben nach SQLite, werden Cursor, Zeitpunkt, Nachricht und Auftrag gemeinsam zurückgerollt. Bereits bestätigte Updates oder solche, die Telegram nach Ablauf seiner Aufbewahrungszeit (höchstens 24 Stunden) gelöscht hat, können so nicht wiederhergestellt werden.

Die Kennungen haben unterschiedliche Aufgaben:

| Feld | Bedeutung |
| --- | --- |
| `update_id` im Telegram-Update | Ereignisnummer, aus der `next_offset` berechnet wird. |
| `recipient_message_id` in `messages` | Nachrichtennummer im Bot-Chat; nicht für den Cursor verwendet. |
| `origin_message_id` in `messages` | Nachrichtennummer der ursprünglichen Kanalveröffentlichung, falls von Telegram beim Weiterleiten mitgeteilt; nicht für den Cursor verwendet. |

Update `104` kann beispielsweise Nachricht `312` im Bot-Chat und Veröffentlichung `87` aus einem Kanal enthalten. Der Cursor wird `105`. `312` und `87` beeinflussen ihn nicht.

Der Cursor ist weder eine Angebots- noch eine Quellkennung. Er dient nur dem geordneten Empfang von Updates über `getUpdates`. Siehe [`SqliteInboxRepository::pollingOffset()` und `applyUpdate()`](../../src/Storage/SqliteInboxRepository.php).

## Was passiert bei einem Webhook?

Im Webhook-Betrieb sendet Telegram Anfragen an den Server. Sie können erneut oder in falscher Reihenfolge eintreffen. Statt eines Polling-Cursors speichert die Anwendung jede `update_id` in `received_updates` und prüft, ob das Update bereits eingegangen ist.

Beispielsweise wird eine Anfrage mit `update_id = 205` erfolgreich gespeichert und Telegram erhält HTTP 204. Bei erneuter Zustellung entsteht kein neuer Auftrag. Scheitert der Datenbankschreibzugriff, antwortet der Server mit einem Fehler und Telegram kann die Zustellung wiederholen.

Der Mechanismus ist in [`SqliteInboxRepository::applyWebhookUpdate()`](../../src/Storage/SqliteInboxRepository.php) und [`WebhookReceiver`](../../src/Application/WebhookReceiver.php) umgesetzt.

## Welche Stellenangebote sind Duplikate?

Die Anwendung verwendet zwei Arten von Kennungen:

- **Nachricht im Bot-Chat.** Die wiederholte Zustellung derselben Nachricht mit derselben ID erzeugt kein weiteres Angebot.
- **Ursprüngliche weitergeleitete Veröffentlichung.** Wenn Telegram die Kennungen des Quellkanals und der Veröffentlichung mitteilt, führt ein weiteres Weiterleiten nicht zu einer neuen Bewertung, selbst wenn die neue Chat-Nachricht eine andere ID hat.

Du leitest beispielsweise heute eine Stelle aus einem Kanal weiter und morgen dieselbe erneut. Sind die Quellkennungen vorhanden, antwortet die Anwendung `Эта публикация уже сохранена.` („Diese Veröffentlichung wurde bereits gespeichert“). **Gleicher Text allein gilt nicht als Duplikat:** Verschiedene Anzeigen können wortgleich sein.

Die Regeln stehen in [`IncomingUpdateProcessor`](../../src/Application/IncomingUpdateProcessor.php); die Eindeutigkeitsschlüssel für Nachrichten in [`SqliteSchema`](../../src/Storage/SqliteSchema.php).

## Was passiert nach dem Speichern?

1. Der Bot bestätigt mit `Вакансия сохранена.` („Stellenangebot gespeichert“).
2. Die Anwendung analysiert den gespeicherten Text mit Profil und Anweisungen. Auf dem Server erledigt dies ein über cron gestarteter Worker.
3. Die fertige Bewertung wird in SQLite gespeichert und an Telegram gesendet.
4. Scheitert der Versand vorübergehend, versucht die Anwendung erneut, **das gespeicherte Ergebnis zu senden**, ohne eine neue Analyse auszuführen. Bei Bot-API-Antwort HTTP 429 wird ein gültiges `parameters.retry_after` beachtet, wenn es größer als die lokale Verzögerung ist.
5. Nach einer Unterbrechung wird die noch ausstehende Arbeit beim nächsten Start unter Berücksichtigung der verbleibenden Versuche fortgesetzt.

Bei einem Netzwerkfehler kann die Meldung über den Analysebeginn ausbleiben, obwohl die Analyse weiterläuft. Sind sämtliche Versuche verbraucht, beginnt die Analyse nicht automatisch erneut. Wenn Telegram die endgültige Nachricht erhalten hat, die Bestätigung aber die Anwendung nicht erreicht, kann die Abschlussmeldung in seltenen Fällen doppelt eintreffen.

[`SqliteAnalysisRepository`](../../src/Storage/SqliteAnalysisRepository.php) speichert Analyse- und Versandstatus; [`VacancyAnalysisPipeline`](../../src/Application/VacancyAnalysisPipeline.php) führt die Schritte aus. Siehe [Bewertungs- und Benachrichtigungsformat](../reference/analysis-result_de.md).
