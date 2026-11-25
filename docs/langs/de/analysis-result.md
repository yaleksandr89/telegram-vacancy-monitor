# Format der Bewertung und Benachrichtigungen

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/analysis-result.md) | [English](../en/analysis-result.md) | [Español](../es/analysis-result.md) | [中文](../zh/analysis-result.md) | [Français](../fr/analysis-result.md) | **Ausgewählt** |

[← Zurück zum README](README.md)

Die Anwendung erhält eine strukturierte Bewertung vom Modell, speichert sie und erstellt eine separate Textnachricht für Telegram. Das JSON des Modells und die Nachricht an den Nutzer haben unterschiedliche Formate.

## API-Antwort und Modell-JSON

[`OpenAiCompatibleEvaluator`](../../../src/Analysis/OpenAiCompatibleEvaluator.php) akzeptiert eine nicht gestreamte Chat-Completions-Antwort: Das erste Element von `choices` muss `message.content` als Zeichenfolge enthalten. Diese Zeichenfolge muss genau ein JSON-Objekt enthalten. Nicht leere `tool_calls` und jeder von null verschiedene `function_call` werden abgelehnt; Werkzeuge werden nicht ausgeführt.

[`EvaluationPromptBuilder::build()`](../../../src/Analysis/EvaluationPromptBuilder.php) fügt das Schema zusammen mit deinen Anweisungen in die Systemnachricht ein. Profil und nicht vertrauenswürdiger Ausschreibungstext werden in einer Nutzernachricht übermittelt, ohne Chat-Metadaten separat zu senden.

Alle folgenden Felder sind Pflichtfelder; zusätzliche Felder sind verboten. [`AnalysisResult::jsonSchema()`](../../../src/Analysis/AnalysisResult.php) definiert die Struktur, und [`AnalysisResultValidator::validate()`](../../../src/Analysis/AnalysisResultValidator.php) führt die Prüfung aus.

| Feld | Typ und zulässige Werte | Bedeutung |
| --- | --- | --- |
| `company` | Nicht leere Zeichenfolge | Unternehmen oder Hinweis, dass es nicht genannt wurde. |
| `position` | Nicht leere Zeichenfolge | Stellenbezeichnung. |
| `verdict` | `apply`, `borderline`, `skip` | Entscheidung über die Eignung für eine Bewerbung. |
| `priority` | `high`, `medium`, `low` | Priorität der Stelle. |
| `matches` | Array nicht leerer Zeichenfolgen; darf leer sein | Übereinstimmungen mit dem Kandidatenprofil. |
| `risks` | Array nicht leerer Zeichenfolgen; darf leer sein | Risiken und erhebliche Abweichungen. |
| `geography` | Nicht leere Zeichenfolge | Standort und Arbeitsmodell. |
| `pay` | Nicht leere Zeichenfolge | Vergütung oder Hinweis auf fehlende Angaben. |
| `missing` | Array nicht leerer Zeichenfolgen; darf leer sein | Fragen und fehlende Angaben. |
| `rationale` | Nicht leere Zeichenfolge | Kurze Begründung. |
| `source_status` | In der Modellantwort nur `unavailable` | Das Modell bestätigt die Herkunft der Veröffentlichung nicht. |

Zeichenfolgen müssen gültiges UTF-8 und sichtbaren Text enthalten. Der Validator weist Steuerzeichen, unsichtbare Formatierungszeichen, Zeilentrenner, HTTP/HTTPS-Links und HTML-ähnliche Tags zurück. Zeilenumbrüche innerhalb von Zeichenfolgen sind ebenfalls ungültig; einzelne Punkte gehören in getrennte Array-Elemente. Als einzige äußere Umhüllung ist **ein einzelner Markdown-Codeblock** mit der exakten Kennzeichnung `json` zulässig, den der Adapter vor der strikten Objektprüfung entfernt. Zusätzlicher Text, andere Blöcke und weitere Formatierung werden zurückgewiesen.

Das folgende **synthetische Objektmuster in `message.content`** enthält die russischen Platzhalter des Beispielszenarios:

```json
{
  "company": "Компания из объявления",
  "position": "Должность из объявления",
  "verdict": "borderline",
  "priority": "medium",
  "matches": ["Подтверждённое совпадение"],
  "risks": [],
  "geography": "Формат и география из объявления",
  "pay": "Не указана",
  "missing": ["Условие, которое нужно уточнить"],
  "rationale": "Краткое объяснение на основе профиля и вакансии",
  "source_status": "unavailable"
}
```

In der HTTP-Antwort muss dieses Objekt als **Zeichenfolge** in `choices[0].message.content` kodiert sein und darf dort nicht direkt als Objekt stehen. Die vollständige API-Antwort ist auf 65.536 Byte begrenzt. Eine ungültige Antwortstruktur oder ungültiges JSON kann innerhalb von `AI_MAX_ATTEMPTS` erneut versucht werden.

## Bewertung und Priorität

Die Werte definieren [`Verdict`](../../../src/Analysis/Verdict.php) und [`Priority`](../../../src/Analysis/Priority.php). Die russischen Bezeichnungen stammen aus [`VacancyResultFormatter::format()`](../../../src/Application/VacancyResultFormatter.php).

| Wert | Telegram-Text (Russisch) | Bedeutung |
| --- | --- | --- |
| `apply` | Откликаться | Eine Bewerbung in Betracht ziehen. |
| `borderline` | Погранично | Es bestehen Zweifel oder wichtige offene Fragen. |
| `skip` | Пропустить | Das Angebot nach den festgelegten Kriterien nicht weiterverfolgen. |
| `high` | высокий | Hohe Priorität. |
| `medium` | средний | Mittlere Priorität. |
| `low` | низкий | Niedrige Priorität. |

Die Anwendung prüft zulässige Werte, legt aber weder einen numerischen Grenzwert noch eine feste Kombination von Bewertung und Priorität fest. Die inhaltlichen Kriterien bestimmst du in `instructions.md`; **das Modell trifft die Entscheidung**. Die Bewertung verschickt keine Bewerbung an den Arbeitgeber.

## Telegram-Benachrichtigung

Der [Formatierer](../../../src/Application/VacancyResultFormatter.php) erzeugt eine gewöhnliche Textnachricht. Unten steht eine schematische Anordnung mit Platzhaltern, **kein tatsächliches Ergebnis**. Die Beschriftungen bleiben russisch, wie die Nutzernachrichten der Anwendung:

```text
Откликаться / Погранично / Пропустить
Приоритет: высокий / средний / низкий
{компания} — {должность}
{краткое обоснование}
География и формат: {география и формат}
Оплата: {оплата}
Совпадения: {пункты через точку с запятой}
Риски: {пункты через точку с запятой}
Уточнить: {пункты через точку с запятой}
Источник: {ссылка с пометкой или «ссылка недоступна»}
Сообщение в чате: {ID входящего сообщения}
```

Leere Listen `matches`, `risks` und `missing` werden ausgelassen. Die ID in der letzten Zeile bezieht sich auf die eingegangene Nachricht im Bot-Chat, nicht auf die ursprüngliche Veröffentlichung im Kanal.

Überschreitet die vollständige Nachricht 4.096 UTF-16-Codeeinheiten, wird sie mit `(сокращено для Telegram)` gekürzt. Unternehmen, Position und Begründung erhalten eigene Beschriftungen; Bewertung, Priorität und Quelle bleiben erhalten. Emojis werden nicht mitten im Zeichen abgeschnitten. Die vollständige Bewertung verbleibt in SQLite.

## Quelle und Zuverlässigkeit

Links stammen aus dem Ausschreibungstext, nicht aus der Modellantwort. Die Anwendung öffnet keine Websites und lädt keine Seiten herunter.

- Fehlt ein zulässiger Link, erscheint `Источник: ссылка недоступна` („Quelle: Link nicht verfügbar“).
- Für einen gefundenen HTTP/HTTPS-Link erscheint `ссылка из объявления (не проверена)` („Link aus der Anzeige, ungeprüft“).
- Ein bereits vorhandener Link der Form `https://t.me/c/…/…`, dessen numerische IDs zu den Metadaten der weitergeleiteten Kanalveröffentlichung passen, wird als `совпадает с ID пересланной публикации` („stimmt mit der ID der weitergeleiteten Veröffentlichung überein“) gekennzeichnet.

Die genaue Auswahl und Prüfung erfolgen in [`sourceDescription()` und `verifiedSource()`](../../../src/Application/VacancyResultFormatter.php). Übereinstimmende IDs bestätigen den Bezug zur weitergeleiteten Veröffentlichung, **nicht** die Wahrheit der Stellenanzeige oder die Vertrauenswürdigkeit des Arbeitgebers. Aus Metadaten allein wird keine URL erzeugt.

In der Modellantwort muss `source_status` immer `unavailable` lauten. Beim Speichern mit [`AnalysisResult::toJson()`](../../../src/Analysis/AnalysisResult.php) setzt die Anwendung `verified_origin_ids` selbst, wenn die beschriebene Übereinstimmung von Link und ID bestätigt ist; andernfalls bleibt `unavailable`. Das Modell darf sich diesen verifizierten Status nicht selbst geben.

## Fortschrittsmeldungen

[`IncomingUpdateProcessor`](../../../src/Application/IncomingUpdateProcessor.php) und [`VacancyAnalysisPipeline`](../../../src/Application/VacancyAnalysisPipeline.php) trennen Empfang und Verarbeitung.

| Ereignis | Meldung oder Status |
| --- | --- |
| Neue Stelle gespeichert | `Вакансия сохранена.` |
| Bekannte Veröffentlichung erneut gesendet | `Эта публикация уже сохранена.` |
| KI-Anfrage gestartet | `Вакансия передана на анализ ИИ. Результат придёт сюда, как только анализ завершится.` |
| Exakte Vorfilter-Ablehnung | `Пропустить` und Begründung für die genaue Übereinstimmung, ohne KI-Bewertung oder Startmeldung. |
| Endgültiger Analysefehler | `Не удалось обработать` und `Техническая ошибка анализа. Исходная вакансия сохранена.`, gefolgt von Quelle und Nachrichten-ID. |
| Endgültiger Zustellfehler | `delivery_status = failed` in SQLite; es wird keine separate Fehlermeldung verschickt. |

## Verarbeitungsschritte

1. **Speichern.** Die Anwendung speichert Stelle und Aufgabe in SQLite. Nach erfolgreicher Speicherung sendet der Bot `Вакансия сохранена.`. Eine doppelte Zustellung desselben Updates erzeugt keine zweite Stelle.
2. **Analysebeginn.** Der Bot versucht einmal, eine Startmeldung zu senden. Auch wenn sie nicht ankommt, läuft die Analyse weiter.
3. **Bewerten.** Das Modell vergleicht die Stelle mit Profil und Anweisungen. Die fertige Bewertung wird **vor** dem Versand an Telegram in SQLite gespeichert.
4. **Zustellen.** Der Bot sendet das gespeicherte Ergebnis. Bei einem vorübergehenden Zustellfehler ist ein erneuter Versand **ohne neuen Modellaufruf** möglich.
5. **Fortsetzen.** Nach dem Neustart werden nicht abgeschlossene Aufgaben unter Berücksichtigung bereits verbrauchter Versuche fortgesetzt. Aufgebrauchte Aufgaben starten nicht automatisch erneut.

Kommt es beim Senden zu einem Netzfehler, kann Telegram die Nachricht bereits empfangen haben, obwohl die Anwendung keine Bestätigung bekam; das Ergebnis kann dann doppelt zugestellt werden. Mehr zu Wiederholungen, Cursor und Speicherung steht im [Leitfaden zur Verarbeitung](message-processing.md). Die Implementierung erfolgt in [`VacancyAnalysisPipeline`](../../../src/Application/VacancyAnalysisPipeline.php).
