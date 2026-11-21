# Application locale

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](local-gum.md) | [English](local-gum_en.md) | [Español](local-gum_es.md) | [中文](local-gum_zh.md) | **Sélectionné** | [Deutsch](local-gum_de.md) |

[← Retour au README](../readme/README_fr.md)

Envoyez le texte d’une offre au bot ou transférez une annonce depuis une discussion ou un canal. L’application enregistre l’offre, l’évalue selon votre profil et vous transmet le résultat. Gardez la fenêtre ouverte pendant la surveillance.

## Choisir l’archive

Les archives vérifiées seront publiées sur la page [Releases](https://github.com/yaleksandr89/telegram-vacancy-monitor/releases) après les contrôles natifs. Avant la première version publiée, aucune archive prête à l’emploi n’y est disponible. Les noms ci-dessous désignent les futures compilations publiées.

| Plateforme | Nom de l’archive | Configuration nécessaire |
| --- | --- | --- |
| Linux x86_64 | `telegram-vacancy-monitor-linux-x86_64.tar.gz` | Terminal interactif, Bash et `dirname` |
| Windows x64 | `telegram-vacancy-monitor-windows-x64.zip` | Windows 10/11 et Windows PowerShell 5.1 intégré |
| macOS ARM64 | `telegram-vacancy-monitor-macos-arm64.tar.gz` | macOS 14+ sur Apple Silicon et terminal interactif |

L’archive contient un dossier `telegram-vacancy-monitor/` avec l’exécutable, PHP, Gum, des exemples de configuration, `LICENSE` et deux guides : `docs/guides/local-gum.md` et `docs/guides/vds-webhook.md`. Le reste de la documentation est disponible dans le dépôt. Sous Windows, PowerShell lance le menu Gum. Il n’est pas nécessaire d’installer PHP, Composer ou Gum séparément. Le serveur de modèle doit être lancé à part.

Conservez l’arborescence complète. Linux utilise les bibliothèques et le chargeur fournis ; macOS utilise aussi les bibliothèques système. L’archive ARM64 cible Apple Silicon et la version Windows x64 les systèmes Windows x64. macOS peut bloquer l’application téléchargée : vérifiez la provenance de l’archive et le message des réglages de sécurité, sans désactiver toute la protection du système.

Le [script de paquetage](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/build/common/package.py) définit le contenu. Avant le lancement, l’exécutable vérifie les fichiers publics avec `SHA256SUMS.json`. Conservez les paramètres dans les fichiers ci-dessous.

## Extraction et démarrage

Extrayez l’archive dans un dossier personnel accessible en écriture. Exécutez les commandes depuis le répertoire contenant l’archive téléchargée.

Linux :

```bash
# Extraire l’archive et lancer l’application depuis le dossier créé.
tar -xzf telegram-vacancy-monitor-linux-x86_64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Windows : extrayez le ZIP et ouvrez `telegram-vacancy-monitor/vacancy-monitor.exe` par **double-clic**. PowerShell permet également de le lancer :

```powershell
# Extraire dans un dossier séparé puis lancer l’application.
Expand-Archive -LiteralPath .\telegram-vacancy-monitor-windows-x64.zip -DestinationPath .\vacancy-monitor-app
Set-Location -LiteralPath .\vacancy-monitor-app\telegram-vacancy-monitor
.\vacancy-monitor.exe
```

macOS sur Apple Silicon :

```bash
# Extraire la version ARM64 et la lancer.
tar -xzf telegram-vacancy-monitor-macos-arm64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Pour les lancements suivants sous Windows, double-cliquez sur `vacancy-monitor.exe`. Sous Linux et macOS, exécutez `./vacancy-monitor` depuis le dossier de l’application. Les chemins contenant des espaces sont acceptés.

## Aperçu de l’application

Le menu principal, l’aide et les messages de configuration figurent dans la [présentation de l’interface](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-interface_fr.md). L’interface réelle est disponible en russe et en anglais uniquement, contrairement aux langues des documents.

## Créer la configuration privée

Tous les chemins suivants sont relatifs au dossier de l’exécutable. Copiez l’[exemple](../../config/bot.example.ini) dans `config/bot.ini` uniquement s’il n’existe pas déjà.

Linux/macOS :

```bash
# Créer la configuration sans écraser un fichier existant.
cp -n config/bot.example.ini config/bot.ini

# Préparez le répertoire du profil et copiez les instructions d’exemple.
mkdir -p config/profile/local
cp -n config/profile/instructions.example.md config/profile/local/instructions.md
```

Windows PowerShell :

```powershell
# Ne pas écraser votre configuration lors d’un nouveau paramétrage.
if (-not (Test-Path -LiteralPath .\config\bot.ini)) {
    Copy-Item -LiteralPath .\config\bot.example.ini -Destination .\config\bot.ini
}
# Préparez le dossier contenant vos fichiers privés.
New-Item -ItemType Directory -Path .\config\profile\local -Force | Out-Null
# Copiez les instructions seulement si votre fichier n’existe pas.
if (-not (Test-Path -LiteralPath .\config\profile\local\instructions.md)) {
    Copy-Item -LiteralPath .\config\profile\instructions.example.md -Destination .\config\profile\local\instructions.md
}
```

Modifiez `config/bot.ini` dans un éditeur de texte. Ne saisissez jamais de secrets dans des commandes de terminal.

1. Ouvrez [BotFather](https://t.me/BotFather), créez un bot avec `/newbot` et placez le jeton reçu dans `TELEGRAM_BOT_TOKEN`.
2. Envoyez `/start` directement au bot dans un message distinct, sans transfert ni texte supplémentaire.
3. Dans l’application, choisissez « Узнать Telegram User ID » (« Trouver l’ID Telegram »), puis « Проверить сейчас » (« Vérifier maintenant »). À ce stade, seuls le jeton et le mode `polling` sont nécessaires, pas le profil ni l’IA.
4. Inscrivez **votre ID Telegram numérique** dans `TELEGRAM_ALLOWED_USER_ID`. Si plusieurs ID sont affichés sans pouvoir identifier le vôtre, ne devinez pas : suivez la [procédure du guide du bot](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot_fr.md#trouver-votre-identifiant).
5. Conservez `TELEGRAM_RECEIVE_MODE = "polling"`.

La recherche d’ID ne s’exécute qu’à la demande et ne confirme pas les mises à jour en attente. `identify` utilise le même verrou non bloquant `var/bot.lock` que la surveillance : arrêtez celle-ci sans supprimer le fichier de verrouillage. Si un webhook est enregistré, suivez d’abord le [passage sécurisé en mode local](vds-webhook_fr.md#changer-de-mode). Consultez aussi le [guide du bot en ligne](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot_fr.md).

## Préparer le profil et le modèle

Créez `config/profile/local/candidate.md` dans votre éditeur pour décrire expérience, compétences, travail recherché et contraintes. Définissez les règles d’évaluation dans `config/profile/local/instructions.md`. Les deux fichiers sont obligatoires, non vides, en UTF-8 valide, avec au plus 32 Kio et 16 000 caractères chacun. Voir le [guide du profil](../../config/profile/README_fr.md).

Démarrez un serveur proposant Chat Completions. S’il s’exécute sur la même machine, la configuration peut ressembler à ceci :

```ini
; Exemple d’API locale : indiquez l’identifiant réel du modèle.
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

Remplacez `model-id` par l’identifiant API du modèle chargé et l’adresse par celle de votre serveur. L’application ajoute `/chat/completions` à `AI_BASE_URL`. Le serveur doit accepter les requêtes non diffusées en continu et renvoyer du JSON dans `message.content` selon le schéma fourni. Vérifiez la compatibilité sur une offre de test.

En mode `local`, sont autorisés `localhost`, `127.0.0.0/8`, `[::1]` et les IPv4 privées des plages `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`. Pour un modèle sur une autre machine, utilisez son IPv4 privée et limitez l’accès au réseau nécessaire. Un nom d’hôte du réseau local ne passe pas la vérification. `127.0.0.1` désigne l’ordinateur où l’application s’exécute.

Pour un modèle cloud, renseignez `AI_PROVIDER_LOCATION = "cloud"`, une URL HTTPS, l’identifiant du modèle et `AI_API_KEY`.

Voir [connexion des modèles](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models_fr.md) et [référence INI](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/configuration_fr.md).

## Vérifier la préparation et lancer la surveillance

Sélectionnez « Проверить готовность » (« Vérifier la préparation »). La section « Подробнее » (« Détails ») présente les résultats pour l’environnement, les paramètres, le profil et le stockage. Envoyez ensuite une [offre de test](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/vacancy-analysis_fr.md) pour vérifier la chaîne complète ; cette page d’exemples n’est pas incluse dans l’archive standalone.

Après configuration, choisissez « Запустить мониторинг » (« Démarrer la surveillance ») et envoyez une courte offre au bot. Normalement, il répond `Вакансия сохранена.`, puis envoie un avis de début d’analyse et le résultat. Celui-ci comprend verdict, priorité, justification, conditions, correspondances, risques et questions. La source est indiquée séparément ; un lien de l’annonce n’est pas automatiquement vérifié.

Les requêtes au modèle sont traitées séquentiellement. Pour un modèle lent, passez `AI_REQUEST_TIMEOUT_SECONDS` à `300` ou `600` et redémarrez la surveillance. La plage autorisée est de 30 à 1800 secondes par requête. `AI_MAX_ATTEMPTS` prévoit 1 à 5 tentatives au total, séparément pour l’analyse et l’envoi, première tentative comprise. Une erreur permanente peut arrêter immédiatement l’opération.

L’évaluation est sauvegardée avant son envoi, donc une livraison répétée du message n’impose pas de nouvelle analyse. Voir [doublons et reprise](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/message-processing_fr.md).

## Menu et arrêt

| Action | Fonction |
| --- | --- |
| « Запустить мониторинг » | Recevoir les messages et traiter la file tant que le processus reste ouvert. |
| « Проверить готовность » | Diagnostic local sans appel à l’API. |
| « Узнать Telegram User ID » | Recherche manuelle des auteurs de `/start`. |
| « Справка » | Démarrage rapide, traitement, confidentialité, modèle et serveur. |
| « Язык » | Menu russe ou anglais jusqu’à la fermeture de l’application. |
| « Выход » | Quitter l’application. |

Naviguez avec les flèches et Entrée. Échap revient en arrière ; dans le menu principal, il laisse celui-ci ouvert. Ctrl+C arrête la surveillance et l’application. Après la fin d’une commande ou une erreur, le menu propose réessayer, revenir ou quitter ; il n’y a pas de redémarrage automatique. La langue des réponses du modèle est réglée séparément dans `instructions.md`, pas par la langue du menu.

## Données et mises à jour

Les fichiers personnels sont créés à la configuration et ne figurent pas dans l’archive. Exemples : [`config/bot.example.ini`](../../config/bot.example.ini), [profil Go](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_fr.md#profil-du-candidat), [profil analyste](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_fr.md#profil-du-candidat) et [instructions du modèle](../../config/profile/instructions.example.md).

| Chemin | À conserver |
| --- | --- |
| `config/bot.ini` | Jeton du bot, ID Telegram, connexion IA et règles. |
| `config/profile/local/` | Profil, instructions et sauvegardes privées. |
| `var/` | SQLite, file d’attente et état de traitement. |

Avant une mise à jour, arrêtez l’application et assurez-vous que le processus est terminé. Sauvegardez ces trois chemins de façon privée. Extrayez la nouvelle archive dans un dossier séparé, déplacez les fichiers privés et le dossier `var/` complet vers les emplacements correspondants, comparez votre INI au nouvel exemple et ne lancez que la nouvelle installation. N’extrayez rien par-dessus une installation en cours et ne remplacez pas la base existante par une base vide.

Redémarrez la surveillance après tout changement de configuration, de profil ou d’instructions. Les évaluations déjà stockées ne sont pas recalculées automatiquement.

## En cas de problème

- **Pas d’ID :** envoyez `/start`, puis choisissez encore « Проверить сейчас ». Vérifiez le jeton, l’enregistrement du webhook et `var/bot.lock`.
- **Erreur de paramètres :** contrôlez `config/bot.ini`, les deux fichiers de profil et les détails du diagnostic.
- **Erreur d’authentification :** vérifiez le jeton BotFather, mettez à jour l’INI et redémarrez.
- **Erreur réseau ou TLS :** vérifiez l’accès à Telegram, le DNS, l’horloge et les certificats. Ne désactivez pas TLS.
- **Stockage inaccessible :** contrôlez espace libre et autorisations du dossier d’application, de `var/` et de SQLite. Ne supprimez pas la base ou le verrou pour contourner l’erreur.
- **Offre sauvegardée sans évaluation :** assurez-vous que la surveillance fonctionne, que le serveur de modèle est joignable et qu’il renvoie un JSON valide.
- **Fichiers publics endommagés :** extrayez de nouveau une archive fiable dans un dossier séparé, puis restaurez uniquement vos données.

Aide détaillée : [dépannage](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/troubleshooting_fr.md) et [format du résultat](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result_fr.md).
