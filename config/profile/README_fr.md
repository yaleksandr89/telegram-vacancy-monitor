# Profil du candidat et consignes

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](README.md) | [English](README_en.md) | [Español](README_es.md) | [中文](README_zh.md) | **Sélectionné** | [Deutsch](README_de.md) |

[← Retour au README](../../docs/readme/README_fr.md)

Une évaluation personnalisée nécessite deux fichiers Markdown privés. Ils sont chargés au début du traitement depuis le répertoire fixe `config/profile/local/`, relatif à la racine de l'application.

| Fichier | Contenu |
| --- | --- |
| `config/profile/local/candidate.md` | Expérience, compétences, responsabilités souhaitées, organisation du travail et contraintes du candidat |
| `config/profile/local/instructions.md` | Règles de comparaison avec l'offre, choix du verdict, priorité et langue des explications |

Créez le répertoire `local/`, rédigez `candidate.md` dans un éditeur de texte et copiez l'[exemple de consignes](instructions.example.md) dans `local/instructions.md`. Un simple document Markdown suffit ; aucun titre ni champ particulier n'est obligatoire. Ne modifiez pas l'exemple public à la place de votre copie privée.

## Informations à fournir

Décrivez vos compétences et votre expérience, les missions recherchées, l'organisation du travail, le lieu, la rémunération attendue et les contraintes importantes. Prenez pour modèle l'exemple du [développeur Go](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_fr.md#profil-du-candidat) ou de l'[analyste](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_fr.md#profil-du-candidat).

Dans les consignes, indiquez quelles exigences sont impératives, dans quels cas attribuer une priorité élevée et quelles conditions nécessitent une clarification. L'[exemple de consignes](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_fr.md#consignes-dévaluation) illustre l'organisation des règles. La langue des réponses du modèle se définit également ici. Les consignes fournies avec l'application demandent des explications en russe ; la traduction du guide ne change pas cette règle.

L'application ajoute le schéma JSON aux consignes et transmet séparément le profil et le texte de l'offre. Conservez l'obligation de retourner un seul objet JSON sans champs supplémentaires, liens ni mise en forme. Une offre constitue une donnée à analyser, et non une source d'instructions à exécuter par le modèle. [`EvaluationPromptBuilder`](../../src/Analysis/EvaluationPromptBuilder.php) décrit la construction de la requête ; les champs de réponse sont détaillés dans la [référence en ligne](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result_fr.md).

Exemples prêts à copier : [profil et consignes d'un développeur Go](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_fr.md) et d'un [analyste produit](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_fr.md).

## Contraintes des fichiers

- Les deux fichiers sont obligatoires, non vides et lisibles ; les liens symboliques sont interdits.
- L'encodage doit être un UTF-8 valide.
- Chaque fichier ne doit pas dépasser 32 768 octets (32 KiB) de texte brut et 16 000 caractères, espaces et retours à la ligne compris.
- Les tabulations et retours à la ligne ordinaires sont autorisés ; les autres caractères de contrôle sont interdits.

Les vérifications sont assurées par [`CandidateProfileLoader`](../../src/Analysis/CandidateProfileLoader.php), [`CandidateProfile`](../../src/Analysis/CandidateProfile.php), [`EvaluationInstructionsLoader`](../../src/Analysis/EvaluationInstructionsLoader.php) et [`EvaluationInstructions`](../../src/Analysis/EvaluationInstructions.php). Si un fichier manque ou est invalide, la CLI s'arrête avant la réception et le traitement des messages. La commande `identify` n'a pas besoin de ces fichiers. En mode serveur, le webhook peut enregistrer les messages même si le profil n'est pas prêt, mais `work-once` ne peut pas traiter la file sans celui-ci.

## Recharger les modifications

Après une modification, arrêtez puis redémarrez la surveillance locale. Sur le serveur, la prochaine exécution de `work-once` chargera les nouveaux fichiers ; un processus déjà lancé continue d'utiliser les données qu'il a chargées. Les évaluations enregistrées ne sont pas recalculées automatiquement.

Le répertoire `config/profile/local/` est exclu de Git. Lors d'une mise à jour, conservez les fichiers du profil avec `config/bot.ini` et les données de `var/`. Le raccordement des modèles est expliqué dans le [guide](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models_fr.md).

L'adresse du modèle, sa clé, le délai d'attente et le nombre de tentatives se règlent dans `config/bot.ini` selon l'[exemple de configuration](../bot.example.ini). Le démarrage est décrit dans les guides [local](../../docs/guides/local-gum_fr.md) et [serveur](../../docs/guides/vds-webhook_fr.md).
