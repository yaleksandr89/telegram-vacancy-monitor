# Mitwirken

## Sprache auswählen

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/.github/CONTRIBUTING.md) | [English](../en/CONTRIBUTING.md) | [Español](../es/CONTRIBUTING.md) | [中文](../zh/CONTRIBUTING.md) | [Français](../fr/CONTRIBUTING.md) | **Ausgewählt** |

[← Zurück zum README](README.md)

Telegram Vacancy Monitor freut sich über Vorschläge, Fehlerbehebungen und Verbesserungen. Vielen Dank für dein Interesse am Projekt.

## Fehler und Vorschläge

Nutze [GitHub Issues](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues), um Fehler zu melden und neue Funktionen zu diskutieren. Prüfe bestehende Issues, bevor du einen neuen eröffnest. Größere Änderungen sollten vor der Umsetzung besprochen werden.

Mögliche Schwachstellen sind gemäß der [Sicherheitsrichtlinie](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/policy) zu melden.

## Entwicklung

Für die Arbeit am Quellcode werden PHP 8.5, Composer und die PHP-Erweiterungen `ext-curl`, `ext-json`, `ext-mbstring`, `ext-pdo` und `ext-pdo_sqlite` benötigt.

Installiere Abhängigkeiten mit `composer install`. Prüfe das Projekt mit `composer check`. Änderungen an plattformspezifischen Oberflächen und Bundles müssen zusätzlich auf den betreffenden Betriebssystemen geprüft werden.

`composer check` umfasst die strikte Composer-Validierung, den Audit der Lockdatei, PHP lint, PHP CS Fixer (PER Coding Style 3.1), PHPStan level 8 und Smoke-Tests. Die Entwicklungswerkzeuge benötigen PHP 8.5; die Anwendungskompatibilität mit PHP 8.6 wird separat ohne Entwicklungsabhängigkeiten geprüft. Standalone-Distributionen enthalten keine installierten Entwicklungspakete.

Unter Windows verwenden die Tests der Standalone-Oberfläche PHP 8.5.13 oder eine neuere Patchversion des PHP-8.5-Zweigs. Liegt das System-PHP außerhalb dieses Bereichs, setze vor `composer test` die ausschließlich für Tests vorgesehene Umgebungsvariable `TVM_TEST_BUNDLE_PHP` auf den absoluten Pfad einer geprüften kompatiblen `php.exe`. Das System-PHP dient weiterhin zur Prüfung der Anwendungskompatibilität; die Variable ändert weder die Einstellungen noch die Beschränkungen der Distribution.

## Pull Request

Beschreibe Ziel und Ergebnis der Änderung sowie die durchgeführten Prüfungen. Bei Verhaltensänderungen sind entsprechende Tests hinzuzufügen und die betroffene Dokumentation zu aktualisieren.

Füge Pull Requests, Issues und Logs keine Tokens, API-Schlüssel, privaten Konfigurationsdateien oder personenbezogenen Daten hinzu.

## Lizenz

Der Projektcode steht unter der [MIT-Lizenz](../../../LICENSE).
