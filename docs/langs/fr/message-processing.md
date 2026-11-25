# Réception et traitement des offres par l’application

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/message-processing.md) | [English](../en/message-processing.md) | [Español](../es/message-processing.md) | [中文](../zh/message-processing.md) | **Sélectionné** | [Deutsch](../de/message-processing.md) |

[← Retour au README](README.md)

Lorsque vous envoyez ou transférez une offre au bot, Telegram transmet une mise à jour à l’application. Celle-ci enregistre le texte et crée une tâche d’évaluation. Après l’analyse, le résultat est envoyé dans la même conversation.

## Données conservées

Pour chaque offre, SQLite enregistre le texte, les identifiants des messages et, si disponibles, les informations sur la publication transférée d’origine. Les états d’évaluation et de livraison sont conservés séparément.

Dans le [schéma de la base de données](../../../src/Storage/SqliteSchema.php), ces informations correspondent aux tables `messages` et `analysis_jobs`.

## Utilité du curseur

En mode local, l’application reçoit les mises à jour avec `getUpdates`. Chacune possède un identifiant numérique `update_id`. Le **curseur** conserve la position suivante après la dernière mise à jour traitée et enregistrée de manière fiable. Avant chaque requête, l’application vérifie si cette position reste utilisable.

Exemple :

1. L’application reçoit la mise à jour `104` contenant une nouvelle offre.
2. Elle écrit l’offre dans SQLite, crée une tâche et enregistre le curseur suivant `105`.
3. La requête suivante à Telegram commence avec `offset = 105` : il n’est plus nécessaire de recevoir `104` à nouveau.

L’enregistrement de l’offre et l’avancement du curseur s’effectuent ensemble. En cas d’échec de l’écriture, le curseur reste inchangé et Telegram peut transmettre à nouveau la mise à jour. Si elle a déjà été enregistrée, l’application ne crée pas de second exemplaire.

### Signification des champs du curseur

La table `cursor` contient une seule entrée pour la réception locale :

| Colonne | Exemple | Signification |
| --- | --- | --- |
| `name` | `main` | Nom fixe du curseur, pas celui du bot ni de la conversation. |
| `next_offset` | `105` | Position après le dernier `update_id` enregistré, vérifiée avant utilisation. |
| `last_handled_at` | `1795000000` | Horodatage Unix de l’enregistrement du traitement avec le curseur. Peut être `NULL` après migration d’une ancienne base. |

**`next_offset` est calculé à partir de `update_id`, pas du numéro du message.** Après la mise à jour `104`, on enregistre `105`. Dans une séquence courante, le curseur ne diminue pas ; les réponses `getUpdates` vides ne modifient pas `last_handled_at`. L’horodatage et le curseur sont enregistrés dans la même transaction que le message et la tâche, s’ils existent.

### Reprise après une longue inactivité

Telegram peut attribuer de nouveaux `update_id` inférieurs après au moins une semaine sans nouvelles mises à jour. Envoyer l’ancien `offset` élevé pourrait alors confirmer auprès du Telegram Bot API des mises à jour de faible ID encore non traitées.

C’est pourquoi, avant **chaque** requête `getUpdates`, l’application envoie `offset = 0` si `last_handled_at` est absent, invalide, futur ou vieux de plus de cinq jours. Elle n’efface pas l’ancien curseur à l’avance. La première mise à jour correctement enregistrée définit la nouvelle position, même si son ID est inférieur à l’ancien. Les contraintes d’unicité SQLite continuent d’empêcher les doublons d’offres et de tâches.

Si une nouvelle réponse contient des ID inférieurs à l’`offset` demandé ou si les mises à jour arrivent dans le désordre, la réception s’arrête sans avancer le curseur. En cas d’échec d’écriture SQLite, curseur, horodatage, message et tâche sont annulés ensemble. Ce mécanisme ne peut pas récupérer les mises à jour déjà confirmées par Telegram ou supprimées après la durée de conservation (24 heures au maximum).

Les différents identifiants ont des fonctions distinctes :

| Champ | Signification |
| --- | --- |
| `update_id` d’une mise à jour Telegram | Numéro de l’événement utilisé pour calculer `next_offset`. |
| `recipient_message_id` dans `messages` | Numéro du message dans la conversation avec le bot ; inutilisé pour le curseur. |
| `origin_message_id` dans `messages` | Numéro de la publication d’origine du canal, si Telegram le transmet lors du transfert ; inutilisé pour le curseur. |

Par exemple, la mise à jour `104` peut contenir le message `312` dans la conversation avec le bot et la publication `87` du canal. Le curseur devient `105` ; les numéros `312` et `87` ne l’influencent pas.

Le curseur n’est ni un identifiant d’offre ni un identifiant de source. Il sert uniquement à recevoir les mises à jour `getUpdates` dans l’ordre. Voir [`SqliteInboxRepository::pollingOffset()` et `applyUpdate()`](../../../src/Storage/SqliteInboxRepository.php).

## Et en mode webhook ?

En mode webhook, Telegram envoie des requêtes au serveur. Elles peuvent être répétées ou arriver dans le désordre. À la place d’un curseur de polling, l’application inscrit chaque `update_id` dans la table `received_updates` et vérifie si la mise à jour a déjà été reçue.

Par exemple, une requête contenant `update_id = 205` est enregistrée et Telegram reçoit HTTP 204. Si elle est transmise de nouveau, aucune tâche supplémentaire n’est créée. Si l’écriture en base échoue, le serveur renvoie une erreur et Telegram peut réessayer.

Ce mécanisme est implémenté dans [`SqliteInboxRepository::applyWebhookUpdate()`](../../../src/Storage/SqliteInboxRepository.php) et [`WebhookReceiver`](../../../src/Application/WebhookReceiver.php).

## Offres considérées comme des doublons

L’application utilise deux types d’identifiants :

- **Message dans la conversation avec le bot.** Une nouvelle livraison du même message avec le même ID ne crée pas une autre offre.
- **Publication transférée d’origine.** Si Telegram fournit les identifiants du canal et de la publication d’origine, un nouveau transfert de cette publication ne déclenche pas une autre évaluation, même si le nouveau message du bot a un ID différent.

Par exemple, vous transférez une offre depuis un canal aujourd’hui, puis à nouveau demain. Si les identifiants d’origine sont disponibles, l’application répond `Эта публикация уже сохранена.` (« Cette publication est déjà enregistrée »). **Un texte identique ne suffit pas pour conclure à un doublon :** deux annonces différentes peuvent contenir le même texte.

Les règles sont dans [`IncomingUpdateProcessor`](../../../src/Application/IncomingUpdateProcessor.php) et les contraintes d’unicité dans [`SqliteSchema`](../../../src/Storage/SqliteSchema.php).

## Traitement après l’enregistrement

1. Le bot confirme par `Вакансия сохранена.` (« Offre enregistrée »).
2. L’application analyse le texte enregistré selon le profil et les consignes. Sur le serveur, un traitement déclenché par cron effectue l’analyse.
3. L’évaluation terminée est enregistrée dans SQLite puis envoyée à Telegram.
4. Si l’envoi échoue temporairement, l’application réessaie **d’envoyer le résultat déjà enregistré** sans lancer une nouvelle évaluation. Pour une réponse HTTP 429 du Bot API, elle respecte un `parameters.retry_after` valide s’il dépasse le délai local.
5. En cas d’interruption, les tâches inachevées reprennent à la prochaine exécution, dans la limite des tentatives restantes.

La notification de début d’analyse peut ne pas arriver en cas de problème réseau alors que l’analyse continue. Lorsque toutes les tentatives sont épuisées, l’analyse ne redémarre pas automatiquement. Dans le cas rare où Telegram reçoit le message final mais où l’accusé de réception n’atteint pas l’application, la notification finale peut être répétée.

[`SqliteAnalysisRepository`](../../../src/Storage/SqliteAnalysisRepository.php) conserve les états ; [`VacancyAnalysisPipeline`](../../../src/Application/VacancyAnalysisPipeline.php) effectue les actions. Voir le [format de l’évaluation et des notifications](analysis-result.md).
