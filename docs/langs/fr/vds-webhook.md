# Serveur : webhook et cron

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/vds-webhook.md) | [English](../en/vds-webhook.md) | [Español](../es/vds-webhook.md) | [中文](../zh/vds-webhook.md) | **Sélectionné** | [Deutsch](../de/vds-webhook.md) |

[← Retour au README](README.md)

Le serveur reçoit les offres via HTTPS, les enregistre dans SQLite et les traite selon un calendrier. Vous pouvez envoyer le texte d’une offre au bot ou transférer une publication depuis une discussion.

[`public/webhook.php`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/public/webhook.php) enregistre les messages entrants. `php bin/bot.php work-once` exécute l’évaluation IA et envoie les résultats. Pour un seul jeton de bot, utilisez **ce mode ou le polling local**, jamais les deux simultanément.

## Préparer l’environnement

Placez les fichiers du dépôt dans un répertoire permanent du serveur, **hors** du répertoire public du site.

Il faut PHP `^8.5` pour CLI et PHP-FPM, Composer, HTTPS avec certificat valide, et les extensions `curl`, `json`, `mbstring`, `PDO`, `pdo_sqlite`. Vérifiez les extensions séparément pour CLI et FPM : leurs configurations peuvent différer. Les exigences figurent dans [`composer.json`](../../../composer.json).

Depuis la racine de l’installation serveur :

```bash
# Installer les dépendances selon le composer.lock du projet.
composer install --no-dev --no-scripts --no-plugins
```

Utilisez le `composer.lock` du projet. Composer sert à préparer l’installation, pas à traiter les requêtes webhook.

Configurez votre serveur HTTP et un pool PHP-FPM dédié selon votre système :

- DocumentRoot doit désigner **strictement** `public/` ; l’unique point PHP public est `/webhook.php`.
- Désactivez le listing des dossiers, l’exécution d’autres chemins PHP et les alias ou chemins de secours exposant la racine du projet.
- N’exposez jamais `config/`, `vendor/`, `var/`, `.git/` et les sources par HTTP.
- Limitez le corps des requêtes à 256 Kio ; l’application vérifie également cette limite.
- Désactivez `display_errors` dans FPM. Les corps de requêtes, jetons, en-têtes secrets et textes d’offres ne doivent pas apparaître dans les journaux HTTP.

Les directives exactes dépendent du serveur HTTP. Avant d’enregistrer le webhook, vérifiez depuis l’extérieur HTTPS et l’inaccessibilité des chemins privés.

## Créer la configuration et le profil

Si `config/bot.ini` n’existe pas, copiez l’[exemple](../../../config/bot.example.ini). Modifiez-le dans un éditeur plutôt que d’entrer les jetons en ligne de commande.

1. Créez le bot avec [BotFather](https://t.me/BotFather), enregistrez le jeton dans `TELEGRAM_BOT_TOKEN`.
2. Tant que `polling` est choisi et qu’aucun webhook n’est enregistré, envoyez directement `/start` et exécutez `php bin/bot.php identify`. Si `var/bot.lock` est occupé, arrêtez d’abord l’autre polling.
3. Inscrivez votre ID dans `TELEGRAM_ALLOWED_USER_ID`.
4. Préparez `config/profile/local/candidate.md` et `config/profile/local/instructions.md` selon le [guide du profil](profile.md).
5. Réglez `AI_PROVIDER_LOCATION`, `AI_BASE_URL`, `AI_MODEL` et, si nécessaire, `AI_API_KEY`. La [documentation du modèle](ai-models.md) décrit la connexion et la transmission des données.

Pour un modèle sur le même VDS, l’adresse `http://127.0.0.1:1234/v1` est admise en mode `local`. Sur une autre machine, utilisez une IPv4 privée accessible depuis le VDS. Le mode cloud exige HTTPS et une clé API.

Après avoir obtenu l’ID, modifiez les paramètres :

```ini
; Activer webhook, puis remplacer le secret vide par une valeur générée.
TELEGRAM_RECEIVE_MODE = "webhook"
TELEGRAM_WEBHOOK_SECRET = ""
```

Remplacez le secret vide par une valeur aléatoire de 1 à 256 caractères parmi `A–Z`, `a–z`, `0–9`, `_`, `-`. Par exemple, générez 64 caractères avec PHP :

```bash
# Générer un secret sans l’inscrire dans l’historique des commandes.
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Copiez la valeur dans `TELEGRAM_WEBHOOK_SECRET` du `config/bot.ini` privé. Ne la publiez pas dans les journaux ou captures d’écran. `webhook set` l’envoie à Telegram comme `secret_token` ; ne l’insérez **pas** dans l’URL.

Tous les paramètres figurent dans la [référence de configuration](configuration.md).

## Régler les droits et préparer SQLite

CLI et FPM doivent utiliser **le même chemin absolu d’installation** et la même base `var/vacancies.sqlite`. Privilégiez un compte non privilégié dédié à cron et au pool PHP-FPM.

Ce compte doit pouvoir lire le code, `vendor/`, l’INI et le profil, et écrire dans la base et `var/` : SQLite crée un journal près de la base et CLI crée `bot.lock`. N’accordez pas l’accès aux autres utilisateurs. Si CLI et FPM ont des comptes distincts, configurez des ACL ciblées, avec des ACL par défaut pour les nouveaux fichiers. CLI/FPM utilisent `umask(0077)` : un groupe partagé ne suffit pas.

Vérifiez les autorisations **pour les deux comptes**. Ne lancez pas de `chown` ou `chmod` récursif général sur tout le projet.

Avant l’enregistrement du webhook, exécutez en tant qu’utilisateur cron :

```bash
# Crée le schéma SQLite et peut traiter les tâches en attente : PAS un dry-run.
php bin/bot.php work-once
```

Utilisez la bonne version PHP CLI. Cette commande valide les réglages et le profil, crée `var/` et le schéma si nécessaire et **traite aussi les tâches déjà en attente**. Le webhook peut recevoir des messages une fois la base créée.

Avant toute mise à jour, sauvegardez de façon privée l’INI, le profil et SQLite avec les gestionnaires arrêtés. Ne remplacez jamais une base existante par une base vide et ne videz pas la file pour tester.

## Enregistrer le webhook

Assurez-vous que Telegram peut atteindre `/webhook.php` via HTTPS. Sans en-tête secret, la réponse est HTTP 403 ; une requête GET reçoit HTTP 405. Avant l’enregistrement, arrêtez tous les `run` et `identify` de ce bot : polling et webhook sont incompatibles simultanément.

Dans une installation source, utilisez les commandes intégrées `php bin/bot.php webhook`. Elles lisent le jeton et, si nécessaire, le secret dans `config/bot.ini` privé, sans les taper dans le terminal.

| Commande | Fonction |
| --- | --- |
| `php bin/bot.php webhook set <https-url>` | Enregistrer à l’adresse HTTPS indiquée ; exige le mode `webhook` et un secret valide dans l’INI. |
| `php bin/bot.php webhook info` | État d’enregistrement, nombre de mises à jour en attente et dernière erreur d’envoi. |
| `php bin/bot.php webhook delete` | Supprimer l’enregistrement en conservant les mises à jour en attente. |

L’implémentation appelle [setWebhook](https://core.telegram.org/bots/api#setwebhook), [getWebhookInfo](https://core.telegram.org/bots/api#getwebhookinfo) et [deleteWebhook](https://core.telegram.org/bots/api#deletewebhook) officiels. L’enregistrement utilise `allowed_updates=["message"]`, `drop_pending_updates=false`, `max_connections=1`. La suppression utilise aussi `drop_pending_updates=false`.

### Gérer le webhook

Une fois `TELEGRAM_RECEIVE_MODE = "webhook"` défini et `TELEGRAM_WEBHOOK_SECRET` non vide, exécutez à la racine de l’installation (remplacez l’adresse par **votre domaine HTTPS public réel**) :

```bash
# Enregistrer votre adresse HTTPS et vérifier l’état.
php bin/bot.php webhook set 'https://bot.example.org/webhook.php'
php bin/bot.php webhook info
```

L’URL de `set` doit avoir un nom DNS public et HTTPS sur le port 443, 80, 88 ou 8443. Identifiants URL, query et fragment sont interdits. En cas de succès, `set` affiche `Webhook зарегистрирован.`. `info` renseigne enregistrement, mises à jour en attente et erreurs, **mais n’affiche pas l’URL enregistrée et ne la compare pas à celle fournie**.

Après vérification, activez cron. Pour retirer le webhook :

```bash
# Supprimer l’enregistrement en conservant les mises à jour, puis vérifier.
php bin/bot.php webhook delete
php bin/bot.php webhook info
```

Après `delete`, attendez `Webhook удалён. Ожидающие обновления сохранены.`, puis `Webhook не зарегистрирован.`. `info` et `delete` n’exigent que le jeton du bot ; ils fonctionnent même en mode `polling`. Ni SQLite, ni profil, ni réglage IA ne sont requis. Ne créez pas de scripts PHP temporaires appelant Bot API directement.

## Lancer le traitement avec cron

Planifiez une exécution chaque minute sous le compte choisi. Remplacez **les deux** chemins absolus :

```cron
# Exécuter chaque minute ; remplacer les deux chemins absolus.
* * * * * /absolute/path/to/php8.5 /absolute/path/to/project/bin/bot.php work-once
```

Le répertoire de travail de cron est sans importance : le [point d’entrée CLI](../../../bin/bot.php) résout les chemins selon son emplacement. Dirigez la sortie vers un journal privé ou l’adresse de l’administrateur, jamais vers un répertoire public.

La commande prend le verrou partagé `var/bot.lock`, reprend les tentatives interrompues et traite jusqu’à 10 lots. Au bout de 20 secondes, aucun nouveau lot ne démarre, mais une requête déjà commencée peut durer jusqu’à `AI_REQUEST_TIMEOUT_SECONDS` (30–1800 secondes). Un worker occupé fait sauter l’exécution cron suivante au lieu de permettre des analyses simultanées. Voir [`BotRunner::workOnce()`](../../../src/Application/BotRunner.php).

Envoyez une courte offre fictive. Attendez d’abord `Вакансия сохранена.`, puis, après cron, le message de début et le résultat. Les confirmations de réception et de démarrage peuvent manquer en cas de problème réseau sans que le message sauvegardé disparaisse.

## Réception et erreurs de livraison

[`WebhookReceiver`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/src/Application/WebhookReceiver.php) vérifie `X-Telegram-Bot-Api-Secret-Token` **avant** de décoder JSON ou d’ouvrir SQLite. La mise à jour, le message et la tâche sont enregistrés dans une transaction. HTTP 204 n’est renvoyé qu’après l’écriture validée dans SQLite. Un `update_id` déjà reçu ne crée pas une nouvelle tâche ; les mises à jour peuvent arriver dans le désordre.

| Code HTTP | Action |
| --- | --- |
| 400 | Vérifier JSON et champs requis de la requête. |
| 403 | Comparer secret INI et enregistrement ; vérifier la transmission de l’en-tête à FPM. |
| 405 | Utiliser POST ; GET ne sert qu’à tester le refus. |
| 413 | Contrôler le corps : 256 Kio maximum. |
| 415 | Utiliser `Content-Type: application/json`. |
| 503 | Vérifier mode, configuration, chargeur Composer, base existante et droits SQLite. |

SQLite attend au plus 5 secondes pour un verrou. Une écriture échouée entraîne 503 afin que Telegram puisse réessayer ; les tentatives sont limitées, corrigez vite la cause. L’accusé de réception vient après l’enregistrement SQLite. Un même `update_id` ne recrée pas de tâche. Voir [gestion des doublons](message-processing.md).

L’avis de début d’analyse n’est tenté qu’une fois par offre. L’évaluation terminée est sauvegardée avant l’envoi et n’est pas recalculée si celui-ci échoue. `AI_MAX_ATTEMPTS` s’applique séparément à l’analyse et à l’envoi. Les tâches définitivement échouées ne se recréent pas automatiquement. Voir [formats et états](analysis-result.md).

## Changer de mode

**De l’application locale au serveur :**

1. Arrêtez la surveillance et terminez tout `identify` en cours. Attendez la fin des traitements déjà démarrés.
2. Processus arrêtés, transférez `config/bot.ini`, le profil et **tout `var/`**, y compris la base SQLite existante, sur le serveur. Ne faites pas tourner deux copies avec le même jeton et des données divergentes.
3. Préparez PHP-FPM, HTTPS, droits et calendrier cron, mais **n’activez pas encore cron et n’enregistrez pas de webhook**. Vérifiez que CLI et FPM partagent la même base.
4. Définissez `TELEGRAM_RECEIVE_MODE = "webhook"` et le secret, puis exécutez `php bin/bot.php work-once` comme utilisateur cron pour préparer le schéma. **Ce n’est pas un dry-run de diagnostic :** si la file contient des tâches, la commande peut appeler l’IA et envoyer des messages. Si vous ne souhaitez pas encore traiter la file, ne lancez pas `work-once` uniquement pour vérifier la configuration.
5. Enregistrez votre URL avec `php bin/bot.php webhook set 'https://bot.example.org/webhook.php'`, en remplaçant l’exemple. Vérifiez `webhook info`, puis activez cron.

N’utilisez qu’une seule copie à jour des données durant le changement. Transférer SQLite ne récupère pas les mises à jour déjà confirmées ou supprimées par Telegram.

**Du serveur à l’application locale :**

1. Désactivez cron et attendez la fin de tout worker actif.
2. Exécutez `php bin/bot.php webhook delete` (conserve les mises à jour), puis `php bin/bot.php webhook info` pour vérifier `Webhook не зарегистрирован.`.
3. Attendez les requêtes PHP-FPM déjà entamées et désactivez le gestionnaire webhook public pour qu’il ne puisse plus écrire dans la base.
4. Gestionnaires arrêtés, conservez la même base et les fichiers privés ; copiez tout `var/` en cas de transfert.
5. Réglez `TELEGRAM_RECEIVE_MODE = "polling"`, puis lancez l’application locale ou `php bin/bot.php run` depuis les sources.

Ne supprimez manuellement ni curseur, ni table des mises à jour reçues, ni messages. Après une longue inactivité, polling remet à zéro un `offset` de sortie devenu non fiable sans effacer les données. `run` et `identify` ne sont pas accessibles avec `webhook` sélectionné ; modifier l’INI ne crée ni ne supprime un webhook : utilisez `webhook set` et `webhook delete`.

En cas de problème, consultez les [conseils locaux](local-gum.md#en-cas-de-problème) ou le [dépannage détaillé](troubleshooting.md).
