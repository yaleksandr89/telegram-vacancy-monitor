# Configuration du bot Telegram

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/telegram-bot.md) | [English](../en/telegram-bot.md) | [Español](../es/telegram-bot.md) | [中文](../zh/telegram-bot.md) | **Sélectionné** | [Deutsch](../de/telegram-bot.md) |

[← Retour au README](README.md)

Un bot créé avec BotFather est nécessaire. `TELEGRAM_ALLOWED_USER_ID` contient l’identifiant numérique du compte Telegram dont l’application accepte les offres et auquel elle transmet les résultats.

## Créer le bot

1. Ouvrez [BotFather](https://t.me/BotFather) dans Telegram et envoyez `/newbot`.
2. Suivez les instructions pour définir le nom affiché et le nom d’utilisateur du bot.
3. Conservez le jeton obtenu dans le fichier privé `config/bot.ini`, créé d’après l’[exemple](../../../config/bot.example.ini).
4. Ouvrez le nouveau bot et envoyez-lui `/start`.

Le jeton donne accès au bot. Conservez-le dans le fichier INI ; ne l’insérez pas dans des commandes, captures d’écran, Issues ou exemples publics. S’il est divulgué, générez-en un nouveau avec BotFather, mettez à jour la configuration et redémarrez le traitement. La [documentation officielle de BotFather](https://core.telegram.org/bots/features#botfather) décrit la gestion des bots.

## Trouver votre identifiant

Pour une première recherche, conservez `TELEGRAM_RECEIVE_MODE = "polling"`. Le jeton doit déjà être renseigné ; l’identifiant utilisateur, le profil et les paramètres du modèle ne sont pas encore nécessaires.

**Dans l’application locale :** lancez `./vacancy-monitor` sous Linux/macOS ou `.\vacancy-monitor.exe` dans PowerShell. Sélectionnez l’option permettant de trouver le Telegram User ID, puis lancez la vérification immédiate. Les menus sont disponibles en russe et en anglais, pas en français.

**Depuis les sources :** exécutez la commande à la racine du projet :

```bash
# Afficher les identifiants des comptes ayant envoyé /start.
php bin/bot.php identify
```

Après vérification, l’identifiant numérique d’un compte ayant envoyé `/start` apparaît. Inscrivez **votre propre identifiant** dans `TELEGRAM_ALLOWED_USER_ID` dans `config/bot.ini`. Un nom commençant par `@` ou l’identifiant du bot ne convient pas.

Si aucun identifiant n’apparaît, renvoyez `/start` au bot puis relancez la vérification. Une publication transférée, une légende de pièce jointe ou un texte comportant des caractères supplémentaires (comme `/starter` ou `/start тест`) ne sont pas interprétés comme la commande de configuration.

Si plusieurs identifiants apparaissent, **ne choisissez pas au hasard**. Dans une installation depuis les sources, lancez `php bin/bot.php identify` et conservez sa sortie. Envoyez ensuite un nouveau `/start` au bot, puis relancez la commande. Si les anciennes lignes sont conservées et qu’une nouvelle ligne `ID отправителя /start: ...` apparaît à la fin, elle correspond à votre message *à condition que personne d’autre n’ait envoyé `/start` pendant ce délai*. Si le résultat reste inchangé ou si des commandes étrangères arrivent, cette méthode ne permet pas de déterminer votre identifiant : `getUpdates` retourne au plus 100 mises à jour en attente. Le menu n’affiche que les identifiants distincts, sans détails sur les expéditeurs. Ne devinez pas et ne videz pas la file des mises à jour pour cette recherche.

La recherche effectue une seule requête `getUpdates`, n’accuse réception d’aucune mise à jour et n’enregistre pas l’identifiant en base. `identify` partage le verrou non bloquant `var/bot.lock` avec `run` ; si le verrou est occupé, aucune requête au Bot API n’est effectuée. Le jeton et le mode `polling` suffisent pour la première recherche. La commande peut créer le répertoire privé `var/` et son fichier de verrouillage, mais n’ouvre pas SQLite. Si un webhook est enregistré, [changez d’abord de mode](vds-webhook.md#changer-de-mode). La logique se trouve dans [`bin/bot.php`](../../../bin/bot.php).

## Terminer la configuration

Préparez le [profil et les consignes](profile.md), puis [connectez l’IA](ai-models.md). Démarrez la surveillance locale ou configurez [webhook et cron](vds-webhook.md).

Pour trouver votre identifiant, envoyez `/start` et effectuez la vérification. Une fois la surveillance démarrée, le bot répond à cette commande en vous invitant à transférer une offre. Les messages qui commencent simplement par `/start` sont traités comme un texte d’offre ordinaire provenant de l’utilisateur autorisé.

## Envoyer des offres

- Envoyez au bot le texte d’une offre dans un message ordinaire.
- Ou transférez-lui une publication depuis un chat ou un canal.
- Le texte d’une légende de pièce jointe peut aussi être traité. Les images et les messages vocaux eux-mêmes ne sont pas reconnus.

Le bot confirme l’enregistrement de l’offre, puis transmet l’évaluation. Voir [les doublons et transferts](message-processing.md) et [la lecture du résultat](analysis-result.md). Si aucun message n’arrive, consultez le [dépannage](troubleshooting.md).
