# Configuration

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](configuration.md) | [English](configuration_en.md) | [Español](configuration_es.md) | [中文](configuration_zh.md) | **Sélectionné** | [Deutsch](configuration_de.md) |

[← Retour au README](../readme/README_fr.md)

La configuration privée se trouve dans `config/bot.ini`, relativement à la racine de l’application. Créez-le à partir de [`config/bot.example.ini`](../../config/bot.example.ini), sans ajouter de secrets à l’exemple public. Les paramètres sont définis dans ce fichier INI.

Le fichier est lu par l’analyseur INI intégré sans conversion des chaînes en booléens. Conservez les guillemets, indiquez les valeurs numériques sous forme d’entiers décimaux et utilisez `[]` pour les éléments répétés des listes. Redémarrez la surveillance locale après modification ; une nouvelle exécution de `work-once` et chaque nouvelle requête webhook chargent le fichier actuel.

## Modes et commandes

Les commandes sont définies dans [`bin/bot.php`](../../bin/bot.php). Sans argument, `run` est sélectionné.

| Commande | Mode | Prérequis |
| --- | --- | --- |
| `php bin/bot.php identify` | `polling` | Jeton du bot et verrou `var/bot.lock` disponible ; ni profil, ni identifiant, ni réglages IA requis. |
| `php bin/bot.php run` | `polling` | Jeton, identifiant Telegram de l’utilisateur, profil, consignes, paramètres IA et accès au stockage. |
| `php bin/bot.php work-once` | `webhook` | Jeton, identifiant Telegram, secret webhook, profil, consignes, paramètres IA et accès au stockage. |
| `php bin/bot.php webhook set <https-url>` | Administration webhook | Jeton, `TELEGRAM_RECEIVE_MODE=webhook`, `TELEGRAM_WEBHOOK_SECRET` valide et adresse HTTPS publique. |
| `php bin/bot.php webhook info` | Administration webhook | Jeton uniquement ; ni ID, ni profil, ni IA, ni mode de réception requis. |
| `php bin/bot.php webhook delete` | Administration webhook | Jeton uniquement ; ni ID, ni profil, ni IA, ni mode de réception requis. |
| `public/webhook.php` via FPM | `webhook` | Jeton, identifiant Telegram, secret webhook et base initialisée ; ni IA ni profil chargés dans la requête HTTP. |

`webhook set` accepte une URL HTTPS comportant un nom DNS public et le port 443, 80, 88 ou 8443. Les URL contenant des identifiants, une query ou un fragment sont refusées. `webhook info` affiche l’enregistrement, le nombre de mises à jour en attente et le dernier message d’erreur, **mais n’affiche pas l’URL enregistrée**. `webhook delete` conserve les mises à jour en attente (`drop_pending_updates=false`). Ces commandes n’ouvrent pas SQLite et ne modifient pas `config/bot.ini` ; le simple choix du mode n’enregistre pas le webhook.

Dans une archive locale, `identify` et `run` sont lancés par le menu avec PHP inclus ; PHP système n’est pas nécessaire. `work-once` et l’administration des webhooks concernent l’installation serveur depuis les sources. `identify` prend un verrou non bloquant avant tout accès au Bot API ; lors de la première vérification, il peut seulement créer le répertoire privé `var/` et le fichier `bot.lock`.

## Telegram

| Clé | Rôle et valeurs admises | Obligatoire / valeur par défaut |
| --- | --- | --- |
| `TELEGRAM_BOT_TOKEN` | Jeton de BotFather au format « chiffres:lettres/chiffres/`_`/`-` ». La conformité au format ne prouve pas la validité du jeton. | Obligatoire pour tout appel au bot ; vide dans l’exemple. |
| `TELEGRAM_ALLOWED_USER_ID` | Identifiant numérique du compte Telegram dont le bot accepte les offres et auquel il transmet le résultat. À obtenir avec `identify` ou le menu après `/start`. | Obligatoire pour `run`, `work-once` et réception webhook ; inutile pour `identify` ou administration webhook ; vide dans l’exemple. |
| `TELEGRAM_RECEIVE_MODE` | `polling` pour `run`/`identify` locaux ; `webhook` pour FPM, `work-once` et `webhook set`. | Facultatif ; `polling` s’il est absent. |
| `TELEGRAM_WEBHOOK_SECRET` | Jeton secret pour authentifier les requêtes webhook : 1–256 caractères parmi `A–Z`, `a–z`, `0–9`, `_`, `-`. Identique au `secret_token` utilisé avec `webhook set`. | Obligatoire pour réception webhook, `work-once` et `webhook set` ; inutile pour `webhook info/delete` ; vide dans l’exemple. |

Modifier `TELEGRAM_RECEIVE_MODE` n’enregistre ni ne supprime un webhook ; utilisez les commandes `webhook set/info/delete`. La validation HTTP se trouve dans [`WebhookReceiver`](../../src/Application/WebhookReceiver.php) et le chargement des paramètres dans [`public/webhook.php`](../../public/webhook.php). La procédure figure dans le [guide serveur](../guides/vds-webhook_fr.md#changer-de-mode).

## Connexion de l’IA

Les paramètres du modèle sont définis dans `config/bot.ini`. [`OpenAiCompatibleEvaluator`](../../src/Analysis/OpenAiCompatibleEvaluator.php) valide les exigences.

| Clé | Rôle et valeurs admises | Obligatoire / valeur par défaut |
| --- | --- | --- |
| `AI_PROVIDER_LOCATION` | `local` ou `cloud` ; détermine les URL autorisées et si une clé est nécessaire. | Facultatif ; `local` par défaut. |
| `AI_BASE_URL` | URL de base sans `/chat/completions`, HTTP/HTTPS, sans identifiant, mot de passe, query, fragment, espace ni caractère de contrôle ; 2048 octets maximum. | Obligatoire, sans URL de remplacement ; exemple `http://127.0.0.1:1234/v1`. |
| `AI_MODEL` | Identifiant non vide du modèle API, UTF-8, 200 octets maximum, sans caractères de contrôle ASCII. | Obligatoire ; vide dans l’exemple. |
| `AI_API_KEY` | Clé pour `Authorization: Bearer`, UTF-8, 4096 octets maximum, sans caractères de contrôle ASCII. | Facultative pour `local` si le serveur ne la demande pas ; obligatoire et non vide pour `cloud` ; vide dans l’exemple. |
| `AI_MAX_ATTEMPTS` | Entier de 1 à 5 : nombre total de tentatives, première comprise, séparément pour l’analyse et la livraison. | Facultatif ; `3` par défaut. |
| `AI_REQUEST_TIMEOUT_SECONDS` | Entier 30–1800 : limite en secondes d’une requête IA. | Facultatif ; `180` par défaut ; une valeur vide ne déclenche pas la valeur par défaut. |

Pour `local`, les hôtes autorisés sont `localhost`, `[::1]`, les plages IPv4 `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12` et `192.168.0.0/16`. Les noms du réseau local et les autres adresses IPv6 ne sont pas admis. Pour `cloud`, HTTPS et une clé non vide sont requis.

Le délai de connexion est fixé à 5 secondes et n’est pas configurable séparément. Les redirections HTTP et proxys sont désactivés pour les requêtes IA ; TLS est vérifié. [`CurlAiTransport`](../../src/Analysis/Http/CurlAiTransport.php) applique ces règles. Les erreurs temporaires entraînent de nouvelles tentatives avec délai ; les erreurs permanentes arrêtent le traitement sans attendre les tentatives restantes. Voir le [guide de connexion des modèles](../guides/ai-models_fr.md#délais-dattente-et-nouvelles-tentatives).

## Préfiltre

Le préfiltre écarte de courts messages prédéfinis sans appeler l’IA. Par défaut, les deux listes sont vides. [`ConservativePrefilter`](../../src/Analysis/ConservativePrefilter.php) applique les règles.

| Clé | Rôle |
| --- | --- |
| `AI_PREFILTER_EXACT_REJECT_LINES[]` | Texte exact du message entier pouvant être écarté immédiatement. |
| `AI_PREFILTER_AMBIGUITY_TERMS[]` | Mot ou expression dont la présence **annule le rejet exact**. |

### Une expression

```ini
; Rejet uniquement lorsque le message entier correspond.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
```

Un message composé seulement de `Только ночная смена.` est écarté sans analyse après suppression des espaces aux extrémités. `Только ночная смена. Есть варианты?` ne correspond plus intégralement et est envoyé au modèle.

### Plusieurs expressions et exceptions

Chaque expression occupe **sa propre ligne**. L’exemple présente trois règles exactes et deux termes d’ambiguïté :

```ini
; Exclusions exactes et termes qui annulent le rejet.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

Une correspondance complète avec l’une des trois expressions provoque le rejet. Si un terme d’ambiguïté est présent, le rejet est annulé et le texte transmis au modèle. La comparaison exacte respecte la casse, tandis que la recherche de termes d’ambiguïté l’ignore.

Chaque élément est **une chaîne non vide**, pas plusieurs règles séparées par des virgules. Les annonces multilignes ne sont pas rejetées : la comparaison porte sur le message entier, pas sur une phrase. Aucune expression régulière n’est utilisée.

## Profil et stockage

Les chemins suivants n’ont pas de clé INI ; ils sont fixés par le [point d’entrée CLI](../../bin/bot.php).

| Chemin | Rôle |
| --- | --- |
| `config/profile/local/candidate.md` | Profil du candidat, indispensable au traitement. |
| `config/profile/local/instructions.md` | Consignes du modèle, indispensables au traitement. |
| `var/vacancies.sqlite` | Messages, résultats et file. |
| `var/bot.lock` | Verrou commun à `run`, `identify` et cron `work-once` ; les requêtes webhook utilisent les transactions SQLite. |

Les limites Markdown sont décrites dans le [guide du profil](../../config/profile/README_fr.md). La configuration, le profil et `var/` contiennent des données privées, sont exclus de Git et doivent être conservés lors des mises à jour.
