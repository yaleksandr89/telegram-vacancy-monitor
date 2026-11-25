# Contribuer

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/.github/CONTRIBUTING.md) | [English](../en/CONTRIBUTING.md) | [Español](../es/CONTRIBUTING.md) | [中文](../zh/CONTRIBUTING.md) | **Sélectionné** | [Deutsch](../de/CONTRIBUTING.md) |

[← Retour au README](README.md)

Telegram Vacancy Monitor accueille les suggestions, corrections de bugs et améliorations. Merci de l’intérêt porté au projet.

## Bugs et propositions

Utilisez les [GitHub Issues](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) pour signaler les bugs et discuter de nouvelles fonctionnalités. Vérifiez les issues existantes avant d’en ouvrir une. Il est préférable de discuter des modifications importantes avant leur mise en œuvre.

Signalez toute vulnérabilité potentielle conformément à la [politique de sécurité](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/policy).

## Développement

Pour travailler sur les sources, PHP 8.5, Composer et les extensions PHP `ext-curl`, `ext-json`, `ext-mbstring`, `ext-pdo` et `ext-pdo_sqlite` sont nécessaires.

Installez les dépendances avec `composer install`. Vérifiez le projet avec `composer check`. Les modifications des interfaces et archives propres aux plateformes doivent également être testées sur les systèmes d’exploitation concernés.

`composer check` inclut la validation stricte de Composer, l’audit du fichier lock, PHP lint, PHP CS Fixer (PER Coding Style 3.1), PHPStan level 8 et les tests smoke. Les outils de développement nécessitent PHP 8.5 ; la compatibilité de l’application avec PHP 8.6 est vérifiée séparément sans dépendances de développement. Les distributions standalone ne contiennent aucun paquet de développement installé.

Sous Windows, les tests de l’interface standalone utilisent PHP 8.5.13 ou une version corrective ultérieure de la branche 8.5. Si le PHP du système est hors de cette plage, définissez la variable d’environnement réservée aux tests `TVM_TEST_BUNDLE_PHP` avec le chemin absolu d’un `php.exe` compatible et vérifié avant d’exécuter `composer test`. Le PHP du système reste utilisé pour vérifier la compatibilité de l’application ; cette variable ne modifie ni les paramètres ni les restrictions de la distribution.

## Pull Request

Décrivez l’objectif et le résultat des modifications ainsi que les vérifications effectuées. Si le comportement change, ajoutez les tests correspondants et mettez à jour la documentation concernée.

Ne placez aucun jeton, clé API, fichier de configuration privé ou donnée personnelle dans les pull requests, issues ou journaux.

## Licence

Le code du projet est distribué sous [licence MIT](../../../LICENSE).
