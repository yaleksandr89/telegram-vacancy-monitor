# Connexion d’un modèle d’IA

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](ai-models.md) | [English](ai-models_en.md) | [Español](ai-models_es.md) | [中文](ai-models_zh.md) | **Sélectionné** | [Deutsch](ai-models_de.md) |

[← Retour au README](../readme/README_fr.md)

L’application utilise des modèles locaux ou hébergés via l’interface Chat Completions. Indiquez l’adresse du serveur et l’identifiant du modèle dans `config/bot.ini` selon l’[exemple](../../config/bot.example.ini).

## Exigences de l’API

Le serveur doit accepter une requête `POST` vers `AI_BASE_URL` suivi de `/chat/completions`, des messages portant les rôles `system` et `user`, ainsi que `stream = false`. La réponse doit contenir `choices[0].message.content` sous forme de chaîne avec un seul objet JSON conforme au schéma inclus par l’application dans les consignes.

`message.content` peut contenir un objet JSON sans encadrement ou dans **un seul** bloc Markdown étiqueté `json`. Du texte ajouté avant ou après l’objet, des blocs supplémentaires, d’autres formes de Markdown et des réponses fragmentées sont refusés. Les appels de fonctions et d’outils sont également rejetés. Le schéma se trouve dans la requête elle-même ; aucun paramètre `response_format` séparé n’est utilisé.

Le format nécessaire est dans la [référence du résultat](../reference/analysis-result_fr.md). Implémentation : [`OpenAiCompatibleEvaluator`](../../src/Analysis/OpenAiCompatibleEvaluator.php) et [`EvaluationPromptBuilder`](../../src/Analysis/EvaluationPromptBuilder.php).

## Modèle local

Démarrez un serveur API avec le modèle chargé, puis configurez :

```ini
; Modèle local : remplacez model-id et, au besoin, l’adresse de l’API.
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

L’adresse et `model-id` sont des exemples à remplacer. Obtenez l’identifiant sur votre serveur : le nom du fichier du modèle peut différer de son nom API. Si le serveur exige une clé, renseignez `AI_API_KEY`.

HTTP et HTTPS sont admis. En mode `local`, la validation autorise :

| Adresse | Utilisation |
| --- | --- |
| `localhost`, IPv4 `127.0.0.0/8`, IPv6 `[::1]` | Serveur du modèle sur le même ordinateur. |
| `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` | Serveur accessible via une adresse IPv4 privée du réseau. |

Les autres noms d’hôte, IP publiques et adresses IPv6 privées ne sont pas admis par cette vérification. Pour un serveur sur une autre machine, utilisez son IPv4 privée. Sous WSL, dans une machine virtuelle ou sur un VDS, `127.0.0.1` désigne l’environnement qui exécute PHP, pas votre ordinateur de bureau.

Limitez l’accès à l’API aux machines nécessaires. L’application n’installe pas le modèle et n’ouvre pas de ports. La mémoire, le matériel et la taille du contexte requis dépendent du modèle choisi. Le contexte doit contenir le profil, les consignes, le schéma et l’offre complète.

## Service cloud

Obtenez une clé auprès du fournisseur choisi, puis récupérez dans sa documentation l’adresse de base et l’identifiant du modèle. Exemple d’adresse fictive :

```ini
; API cloud : remplacez l’adresse et le modèle, puis ajoutez une clé.
AI_PROVIDER_LOCATION = "cloud"
AI_BASE_URL = "https://api.example.org/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

Remplacez l’adresse et le modèle et saisissez une clé non vide dans un éditeur. `cloud` exige HTTPS et une clé. L’URL ne doit pas contenir d’identifiant, de mot de passe, de paramètres de requête ni de fragment. N’ajoutez pas `/chat/completions` à `AI_BASE_URL`.

Le service cloud reçoit le texte de l’offre, le profil et les consignes pour l’analyse. La requête est décrite dans [`EvaluationPromptBuilder::build()`](../../src/Analysis/EvaluationPromptBuilder.php).

## Profil et règles d’évaluation

Créez `config/profile/local/candidate.md` pour l’expérience et les préférences, puis `config/profile/local/instructions.md` pour les règles d’analyse. Commencez avec l’[exemple de consignes](../../config/profile/instructions.example.md) et le [guide du profil](../../config/profile/README_fr.md).

Précisez les exigences obligatoires, la manière de traiter les informations manquantes et la langue des explications. Demandez strictement le JSON selon le schéma de l’application, sans liens, mise en forme ni champs supplémentaires. Les instructions contenues dans une offre ne doivent pas remplacer vos règles. Exemples : [développeur Go](../examples/go-developer_fr.md) et [analyste produit](../examples/analyst_fr.md).

Après modification, redémarrez la surveillance locale ; sur le serveur, la prochaine exécution de `work-once` chargera les changements. Les évaluations déjà enregistrées ne sont pas recalculées.

## Délais d’attente et nouvelles tentatives

```ini
; Nombre de tentatives, première comprise, et secondes par requête IA.
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

`AI_REQUEST_TIMEOUT_SECONDS` est un entier de 30 à 1800 secondes, avec 180 par défaut lorsque la clé est absente. Il s’applique à chaque requête. Le délai de connexion est limité à 5 secondes. Pour un modèle local lent, vous pouvez utiliser `300` ou `600`, puis redémarrer le traitement.

`AI_MAX_ATTEMPTS` prend une valeur de 1 à 5, avec 3 par défaut, première tentative comprise. Cette limite s’applique séparément à l’analyse et à la livraison du résultat. Pour un essai ponctuel d’un modèle lent, la valeur `1` évite une longue attente après un échec.

Les erreurs réseau temporaires, dépassements de délai et réponses HTTP 429 ou 5xx peuvent entraîner une nouvelle tentative. Une réponse mal formée du modèle peut aussi être réessayée. Les erreurs d’autorisation, comme HTTP 401, arrêtent les tentatives suivantes. Les délais entre tentatives sont de 30, 60, 120 et 240 secondes. Les tentatives déjà effectuées sont conservées lors du redémarrage. [`VacancyAnalysisPipeline`](../../src/Application/VacancyAnalysisPipeline.php) gère les nouvelles tentatives et [`CurlAiTransport`](../../src/Analysis/Http/CurlAiTransport.php) les limites réseau.

Les requêtes IA n’utilisent ni redirections HTTP ni proxys, et TLS est vérifié. Le texte de l’offre est limité à 65 536 octets, le corps de réponse API à 65 536 octets et la requête construite à 262 144 octets. Toute réponse trop volumineuse ou invalide est refusée.

## Exclusions exactes facultatives

Le préfiltre permet d’ignorer des messages courts prédéfinis sans les envoyer au modèle.

### Une règle

```ini
; Correspondance du message entier ; un terme ambigu annule le rejet.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
```

### Plusieurs règles et exceptions

Chaque règle occupe une ligne distincte :

```ini
; Définir séparément chaque expression exacte et terme ambigu.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

Dans cet exemple, `Работа только в офисе.` est rejeté seulement s’il constitue tout le message ; `Работа только в офисе. Возможны исключения` est transmis au modèle. Une correspondance exacte contenant également un terme ambigu n’est pas rejetée.

Chaque règle utilise sa propre ligne avec `[]`. Le rejet n’est possible que si **le message entier** correspond, pas si seule une phrase d’une longue offre correspond. La casse est respectée, les espaces aux extrémités ignorés. Les règles multiples figurent dans la [référence de configuration](../reference/configuration_fr.md#préfiltre). Une sous-chaîne dans une offre ne provoque pas de rejet. Tout terme ambigu annule le rejet sans tenir compte de la casse. Les messages comportant des retours à la ligne sont transmis au modèle. Les règles sont des chaînes UTF-8 littérales et non des expressions régulières. Le comportement est implémenté dans [`ConservativePrefilter`](../../src/Analysis/ConservativePrefilter.php).

## Vérifier la connexion

Commencez par la vérification locale de préparation, puis envoyez un court texte d’offre fictif au bot pendant que la surveillance ou cron fonctionne. La vérification de préparation ne contacte pas le serveur du modèle et ne confirme ni la validité de la clé ni la prise en charge du JSON demandé.

Examinez le contenu de la réponse : le modèle distingue-t-il les exigences obligatoires des souhaits, signale-t-il les conditions inconnues et évite-t-il d’inventer de l’expérience ? En cas d’échec, consultez le [dépannage](troubleshooting_fr.md). Si l’évaluation est décevante, affinez le profil et les consignes.
