# Telegram-Bot konfigurieren

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](telegram-bot.md) | [English](telegram-bot_en.md) | [Español](telegram-bot_es.md) | [中文](telegram-bot_zh.md) | [Français](telegram-bot_fr.md) | **Ausgewählt** |

[← Zurück zum README](../readme/README_de.md)

Benötigt wird ein über BotFather erstellter Bot. `TELEGRAM_ALLOWED_USER_ID` enthält die numerische ID des Telegram-Kontos, von dem die Anwendung Stellenangebote annimmt und an das sie Ergebnisse sendet.

## Bot erstellen

1. Öffne [BotFather](https://t.me/BotFather) in Telegram und sende `/newbot`.
2. Folge den Anweisungen, um Anzeigenamen und Benutzernamen des Bots festzulegen.
3. Speichere das erhaltene Token in der privaten Datei `config/bot.ini`, die du anhand der [Vorlage](../../config/bot.example.ini) erstellst.
4. Öffne den neuen Bot und sende `/start`.

Mit dem Token ist ein Zugriff auf den Bot möglich. Bewahre es in der INI-Datei auf und füge es niemals in Befehle, Screenshots, Issues oder öffentliche Beispiele ein. Falls es offengelegt wurde, erstelle über BotFather ein neues Token, aktualisiere die Konfiguration und starte die Verarbeitung neu. Die [offizielle BotFather-Anleitung](https://core.telegram.org/bots/features#botfather) erklärt die Verwaltung.

## Eigene ID ermitteln

Lass für die erste ID-Suche `TELEGRAM_RECEIVE_MODE = "polling"` eingestellt. Das Token muss bereits hinterlegt sein; Benutzer-ID, Profil und Modelleinstellungen sind für die Suche noch nicht erforderlich.

**In der lokalen Anwendung:** starte `./vacancy-monitor` unter Linux/macOS oder `.\vacancy-monitor.exe` in PowerShell. Wähle die Funktion zur Ermittlung der Telegram User ID und danach die sofortige Prüfung. Das Menü ist auf Russisch und Englisch verfügbar, nicht auf Deutsch.

**Bei Ausführung aus dem Quellcode:** führe im Projektverzeichnis Folgendes aus:

```bash
# IDs der Konten anzeigen, die /start gesendet haben.
php bin/bot.php identify
```

Nach der Prüfung erscheint die numerische ID eines Kontos, das `/start` gesendet hat. Trage **deine eigene ID** in `TELEGRAM_ALLOWED_USER_ID` in `config/bot.ini` ein. Ein mit `@` beginnender Benutzername oder die ID des Bots sind ungeeignet.

Wenn keine ID erscheint, sende erneut `/start` und wiederhole die Prüfung. Weiterleitungen, Bildunterschriften von Anhängen und Texte mit zusätzlichen Zeichen (etwa `/starter` oder `/start тест`) gelten nicht als Einrichtungsbefehl.

Falls mehrere IDs erscheinen, **rate nicht**. Führe in einer Quellcodeinstallation `php bin/bot.php identify` aus und speichere die Ausgabe. Sende dann ein neues `/start` an den Bot und wiederhole den Befehl. Wenn die alten Zeilen erhalten bleiben und am Ende eine neue Zeile `ID отправителя /start: ...` hinzukommt, gehört sie zu deiner Nachricht, *sofern in der Zwischenzeit niemand anderes `/start` gesendet hat*. Bleibt die Ausgabe unverändert oder treffen fremde Befehle ein, kann deine ID mit diesem Verfahren nicht zuverlässig ermittelt werden: `getUpdates` liefert höchstens 100 ausstehende Updates. Das Menü zeigt nur eindeutige IDs ohne Absenderinformationen. Rate nicht und lösche die Update-Warteschlange nicht für die Suche.

Die Suche führt genau eine `getUpdates`-Anfrage aus, bestätigt keine Updates und speichert keine ID in der Datenbank. `identify` verwendet dieselbe nicht blockierende Sperre `var/bot.lock` wie `run`; ist sie belegt, erfolgt keine Bot-API-Anfrage. Für die erste Suche werden nur das Token und der Modus `polling` benötigt. Der Befehl kann das private Verzeichnis `var/` und eine Sperrdatei anlegen, öffnet aber SQLite nicht. Falls ein Webhook registriert ist, [wechsle zuerst die Betriebsart](vds-webhook_de.md#modi-wechseln). Der Suchcode liegt in [`bin/bot.php`](../../bin/bot.php).

## Einrichtung abschließen

Bereite [Profil und Anweisungen](../../config/profile/README_de.md) vor und [verbinde anschließend ein KI-Modell](ai-models_de.md). Starte die lokale Überwachung oder richte [Webhook und cron](vds-webhook_de.md) ein.

Sende zur ID-Suche `/start` und führe die Prüfung aus. Bei laufender Überwachung antwortet der Bot auf diesen Befehl mit der Aufforderung, eine Stelle weiterzuleiten. Nachrichten, die nur mit `/start` beginnen, werden als normaler Ausschreibungstext eines berechtigten Benutzers behandelt.

## Stellenangebote senden

- Sende den Ausschreibungstext als normale Nachricht an den Bot.
- Oder leite eine Veröffentlichung aus einem Chat oder Kanal an den Bot weiter.
- Text in der Beschriftung eines Anhangs kann ebenfalls verarbeitet werden. Bilder und Sprachnachrichten selbst werden nicht erkannt.

Der Bot bestätigt die Speicherung und sendet danach die Bewertung. Lies [Duplikate und Weiterleitungen](message-processing_de.md) sowie [das Ergebnisformat](../reference/analysis-result_de.md). Falls keine Nachricht eintrifft, sieh in die [Fehlerbehebung](troubleshooting_de.md).
