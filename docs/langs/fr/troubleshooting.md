# Dépannage

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/troubleshooting.md) | [English](../en/troubleshooting.md) | [Español](../es/troubleshooting.md) | [中文](../zh/troubleshooting.md) | **Sélectionné** | [Deutsch](../de/troubleshooting.md) |

[← Retour au README](README.md)

Commencez par « Проверить готовность » (« Vérifier la préparation ») dans l’application locale et ouvrez « Подробнее » (« Détails ») pour la catégorie signalée. Cette vérification ne contacte ni Telegram ni l’IA, ne lit pas le contenu du profil et n’écrit rien dans SQLite. Une réussite indique uniquement que les conditions locales sont réunies ; la connexion est vérifiée en envoyant un texte de test au bot en fonctionnement.

Les textes d’interface sont dans les [messages de l’interface](../../../src/ui/windows/messages.json) ; les contrôles et catégories d’erreurs dans [`inspect.php`](../../../src/ui/windows/inspect.php). Pour demander de l’aide, indiquez catégorie, code de sortie, système et mode de lancement, sans jeton ni texte privé du profil.

## L’application ne s’ouvre pas

| Symptôme | Que faire |
| --- | --- |
| « Файлы приложения отсутствуют или повреждены » (fichiers absents ou corrompus) | Extrayez une archive fiable dans un nouveau dossier, conservez l’arborescence et ne transférez que vos INI, profil et `var/`. |
| « Нужен интерактивный терминал » (terminal interactif requis) | Lancez depuis un terminal normal sans rediriger entrée, sortie ou erreurs. |
| « Среда PHP приложения неполна или устарела » (environnement PHP incomplet ou ancien) | Vérifiez l’archive adaptée au système et à l’architecture, ainsi que l’extraction complète de `runtime/`. |
| Préparation du lancement ou des diagnostics impossible | Vérifiez l’accès au dossier temporaire système et les droits d’exécution. |
| Windows bloque le script | Vérifiez la stratégie PowerShell de l’organisation et consultez l’administrateur. |
| macOS bloque l’application téléchargée | Vérifiez la provenance de l’archive et la cause indiquée dans les réglages de sécurité macOS. |

Si les fichiers publics sont endommagés, restaurez **toute l’archive** : l’[exécutable](../../../src/launcher/main.go) vérifie leur intégrité à l’aide du manifeste. La [documentation locale](local-gum.md#données-et-mises-à-jour) décrit la réinstallation avec conservation des données.

Pour une installation depuis les sources, PHP `^8.5`, les extensions de [`composer.json`](../../../composer.json) et le chargeur Composer généré sont nécessaires. Si `vendor/autoload.php` manque, installez les dépendances selon `composer.lock` ; il n’est pas nécessaire de modifier le profil ou SQLite.

## Configuration ou profil non prêts

- Créez `config/bot.ini` à partir de l’[exemple](../../../config/bot.example.ini), en vérifiant que l’éditeur n’a pas ajouté `.txt`.
- Vérifiez les guillemets et les noms des clés. Les listes du préfiltre utilisent des clés répétées avec `[]`.
- Indiquez jeton, ID de votre propre compte Telegram dans `TELEGRAM_ALLOWED_USER_ID`, adresse et identifiant du modèle. Pour un modèle cloud, ajoutez la clé API.
- Créez les deux fichiers `config/profile/local/candidate.md` et `config/profile/local/instructions.md`.
- Ils doivent être ordinaires, non vides, en UTF-8 valide, sans lien symbolique, avec au maximum 32 768 octets et 16 000 caractères chacun. Tabulations et retours à la ligne sont autorisés, pas les autres caractères de contrôle.

Si la vérification de préparation accepte un fichier mais que le lancement le refuse, contrôlez encodage, nombre de caractères et contenu : le menu ne teste que l’accessibilité et la taille. Voir le [guide du profil](profile.md) et la [référence de configuration](configuration.md).

Après modification, redémarrez la surveillance locale. Sur le serveur, le prochain `work-once` chargera les nouveaux fichiers.

## ID introuvable

Envoyez `/start` sans texte supplémentaire, puis choisissez à nouveau « Проверить сейчас » (« Vérifier maintenant »), ou exécutez `php bin/bot.php identify` depuis les sources. Une commande transférée ou un `/start` avec paramètres ne convient pas. La recherche ne se fait qu’à la demande et ne confirme pas les mises à jour.

Si `var/bot.lock` est occupé, arrêtez l’autre processus polling puis réessayez, sans effacer le verrou. Dans l’installation source, consultez `php bin/bot.php webhook info` et supprimez l’enregistrement avec `webhook delete` selon la [procédure de changement de mode](vds-webhook.md#changer-de-mode). En présence de plusieurs ID, ne devinez pas : utilisez la [procédure de vérification](telegram-bot.md#trouver-votre-identifiant). Le profil et l’IA ne sont pas nécessaires à cette première recherche.

## Authentification, accès et conflit de réception

| Erreur | Cause et action |
| --- | --- |
| Bot API 401, authentification refusée | Vérifiez le jeton BotFather et remplacez-le dans l’INI après renouvellement. |
| Bot API 403, discussion inaccessible | Vérifiez que la conversation est commencée, le bot non bloqué et l’ID celui de votre compte. |
| Bot API 409 ou conflit polling | Arrêtez la seconde instance et consultez `php bin/bot.php webhook info`. |
| « Занята блокировка var/bot.lock. » | Arrêtez `run` ou `identify` en cours, sans supprimer le verrou. |
| « Недоступна блокировка var/bot.lock. » | Vérifiez les autorisations du dossier privé `var/` et du verrou. |
| Webhook sélectionné, démarrage local interdit | Utilisez `work-once` côté serveur ou terminez le retour à polling. |
| Bot API 429 | L’envoi final tient compte d’un `retry_after` valide ; ne lancez pas plusieurs récepteurs et attendez la nouvelle tentative autorisée. |

Le bot reçoit les mises à jour soit par `getUpdates`, soit par webhook. Modifier l’INI ne retire pas l’enregistrement du webhook. Pour revenir à polling, exécutez `php bin/bot.php webhook delete` (les mises à jour en attente sont conservées), vérifiez « Webhook не зарегистрирован. » avec `webhook info` et suivez la [procédure](vds-webhook.md#changer-de-mode).

Le mode `identify --json` employé par le menu renvoie volontairement une erreur générique sans l’exception d’origine. La catégorie peut rester inconnue ; cela ne prouve pas une cause précise liée au réseau ou aux réglages.

## Erreur réseau ou TLS

Pour Telegram, contrôlez le DNS, l’accès HTTPS à `api.telegram.org`, l’heure système et les certificats de confiance. L’archive standalone doit conserver `runtime/cacert.pem`. Ne désactivez pas la vérification TLS.

Pour l’IA, vérifiez séparément que le serveur est en fonctionnement, le modèle chargé, l’adresse accessible depuis l’environnement de l’application et le port autorisé par le pare-feu. `127.0.0.1` dans WSL, une VM ou un VDS ne désigne pas un autre ordinateur. En mode `local`, utilisez une IPv4 privée accessible.

Le transport IA n’utilise pas de proxy et ne suit pas les redirections HTTP. Indiquez l’adresse de base définitive. Les limites sont définies par [`CurlAiTransport`](../../../src/Analysis/Http/CurlAiTransport.php).

## Offre enregistrée sans évaluation

1. Assurez-vous que la surveillance locale fonctionne toujours. Côté serveur, vérifiez cron, son utilisateur, les chemins absolus de PHP et du projet, et la sortie restreinte de la commande.
2. Tenez compte de la file et des délais de réponse : les requêtes s’exécutent séquentiellement ; sur serveur, le traitement commence selon cron.
3. Vérifiez le modèle et le format API. L’adresse de base ne doit pas inclure `/chat/completions` ; l’application l’ajoute.
4. Pour un modèle lent, passez `AI_REQUEST_TIMEOUT_SECONDS` de `180` à `300` ou `600` et relancez. Plage : 30–1800.
5. Si « Не удалось обработать » apparaît, corrigez la cause avant un nouveau test : l’offre reste dans la base, mais une tâche définitivement échouée ne reprend pas automatiquement.

Un webhook accepté ne prouve pas que l’IA est prête : le gestionnaire HTTP ne charge pas le profil et n’appelle pas le modèle. `work-once` doit réussir son propre démarrage.

Pour localiser l’étape d’échec, ouvrez **votre propre** `var/vacancies.sqlite` avec un client SQLite local **en lecture seule**, pour consulter les états sans texte des offres :

```sql
-- Lecture seule : dix derniers états, sans texte d’offre.
SELECT message_id, analysis_status, analysis_error, analysis_attempts,
       delivery_status, delivery_error, delivery_attempts
FROM analysis_jobs
ORDER BY message_id DESC
LIMIT 10;
```

Les états sont conservés par [`SqliteAnalysisRepository`](../../../src/Storage/SqliteAnalysisRepository.php). Ne modifiez jamais les compteurs ou états à la main. Ne publiez pas la base ; masquez les identifiants personnels avant de partager un extrait de diagnostic.

## Le modèle échoue ou renvoie un format incorrect

| Catégorie | Points à vérifier |
| --- | --- |
| `http_401`, `http_403` | Clé et droits d’accès au modèle. |
| `http_404` | Chemin de base API et identifiant du modèle selon le fournisseur. |
| `http_429`, `http_5xx` | Limites et disponibilité du fournisseur ; ces erreurs peuvent être retentées. |
| `timeout` | Délai, chargement et vitesse du modèle. |
| `invalid_response` | Enveloppe Chat Completions : `choices[0].message.content` comme chaîne, sans appels d’outils. |
| `invalid_output` | Un seul objet JSON avec champs obligatoires et valeurs admises, brut ou dans **un seul** bloc Markdown nommé `json`, sans texte supplémentaire. |
| `response_size` | Réponse API trop longue : maximum 65 536 octets. |
| `vacancy_size_or_encoding` | UTF-8 valide du texte d’offre et limite de 65 536 octets. |
| `transport` | Connexion, TLS et accès au serveur IA. |

`http_5xx` désigne une famille de codes concrets, par exemple `http_503`. Les possibilités de nouvelle tentative dépendent de la cause, pas seulement du nombre de tentatives restantes. Voir le [schéma et les règles de chaînes](analysis-result.md) et le [guide de connexion du modèle](ai-models.md).

## Évaluation en base mais absente du chat

Vérifiez que le bot n’est pas bloqué et que la discussion est accessible. L’envoi du résultat enregistré dispose de ses propres tentatives et ne rappelle pas le modèle. Avec `delivery_status = failed`, le résultat demeure dans SQLite, sans notification séparée d’erreur. Il n’existe pas de commande de renvoi manuel.

L’avis de début d’analyse ne dispose que d’une tentative ; son absence ne signifie pas que la tâche est perdue. Une panne entre l’envoi et la confirmation enregistrée peut produire un doublon. Voir le [traitement des messages](message-processing.md).

## SQLite ou webhook indisponibles

Vérifiez l’espace libre, les droits de lecture/écriture de la base et l’accès à `var/` pour les journaux SQLite. Sur le serveur, CLI et FPM doivent utiliser la même base ; contrôlez les autorisations de chaque compte. L’attente du verrou SQLite est limitée à 5 secondes.

Le webhook répond 503 si le stockage échoue ou n’est pas prêt, et 403 si le secret est incorrect. Vérifiez `TELEGRAM_WEBHOOK_SECRET` dans l’INI privé, puis `php bin/bot.php webhook info`. Si le secret change, enregistrez-le de nouveau avec `webhook set` et votre URL HTTPS. La [documentation serveur](vds-webhook.md) détaille codes HTTP, préparation SQLite et droits.

Ne supprimez pas `var/`, la base ou la configuration locale pour obtenir un démarrage « propre ». Préservez les fichiers privés et la file lors des mises à jour.
