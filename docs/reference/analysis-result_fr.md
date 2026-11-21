# Format de l’évaluation et des notifications

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](analysis-result.md) | [English](analysis-result_en.md) | [Español](analysis-result_es.md) | [中文](analysis-result_zh.md) | **Sélectionné** | [Deutsch](analysis-result_de.md) |

[← Retour au README](../readme/README_fr.md)

L’application reçoit une évaluation structurée du modèle, la conserve et produit un message texte distinct destiné à Telegram. Le JSON renvoyé par le modèle et la notification adressée à l’utilisateur sont deux formats différents.

## Réponse API et JSON du modèle

[`OpenAiCompatibleEvaluator`](../../src/Analysis/OpenAiCompatibleEvaluator.php) accepte une réponse Chat Completions non diffusée en continu : le premier élément de `choices` doit contenir une chaîne `message.content`. Cette chaîne doit renfermer exactement un objet JSON. Les `tool_calls` non vides et tout `function_call` non nul sont rejetés ; aucun outil n’est exécuté.

[`EvaluationPromptBuilder::build()`](../../src/Analysis/EvaluationPromptBuilder.php) place le schéma dans le message système, avec vos instructions. Le profil du candidat et le texte de l’offre, considéré comme non fiable, sont transmis dans le message utilisateur, sans envoi séparé des métadonnées de discussion.

Tous les champs ci-dessous sont obligatoires et les champs supplémentaires sont interdits. [`AnalysisResult::jsonSchema()`](../../src/Analysis/AnalysisResult.php) définit la structure ; [`AnalysisResultValidator::validate()`](../../src/Analysis/AnalysisResultValidator.php) effectue la validation.

| Champ | Type et valeurs autorisées | Signification |
| --- | --- | --- |
| `company` | Chaîne non vide | Entreprise ou mention de son absence. |
| `position` | Chaîne non vide | Intitulé du poste. |
| `verdict` | `apply`, `borderline`, `skip` | Pertinence d’une candidature. |
| `priority` | `high`, `medium`, `low` | Priorité à accorder à l’offre. |
| `matches` | Tableau de chaînes non vides ; peut être vide | Exigences correspondant au profil. |
| `risks` | Tableau de chaînes non vides ; peut être vide | Risques et écarts importants. |
| `geography` | Chaîne non vide | Lieu et mode de travail. |
| `pay` | Chaîne non vide | Rémunération ou absence d’information. |
| `missing` | Tableau de chaînes non vides ; peut être vide | Questions et informations manquantes. |
| `rationale` | Chaîne non vide | Justification succincte. |
| `source_status` | Uniquement `unavailable` dans la réponse du modèle | Le modèle ne vérifie pas l’origine de la publication. |

Les chaînes doivent être en UTF-8 valide et contenir du texte visible. Le validateur rejette les caractères de contrôle, les caractères de formatage invisibles, les séparateurs de ligne, les liens HTTP/HTTPS et les constructions ressemblant à des balises HTML. Les retours à la ligne dans les valeurs sont également refusés ; utilisez plusieurs éléments de tableau. Le seul habillage externe autorisé est **un unique bloc Markdown** étiqueté exactement `json`, retiré par l’adaptateur avant validation stricte. Tout texte ajouté, autre bloc ou balisage est rejeté.

Voici un **modèle d’objet synthétique dans `message.content`** dont les valeurs d’exemple restent en russe pour correspondre à la démonstration :

```json
{
  "company": "Компания из объявления",
  "position": "Должность из объявления",
  "verdict": "borderline",
  "priority": "medium",
  "matches": ["Подтверждённое совпадение"],
  "risks": [],
  "geography": "Формат и география из объявления",
  "pay": "Не указана",
  "missing": ["Условие, которое нужно уточнить"],
  "rationale": "Краткое объяснение на основе профиля и вакансии",
  "source_status": "unavailable"
}
```

Dans la réponse HTTP, cet objet doit être encodé sous forme de **chaîne** dans `choices[0].message.content`, et non y être imbriqué comme objet. La réponse API complète est limitée à 65 536 octets. Une enveloppe ou un JSON invalide peut entraîner une nouvelle tentative dans la limite de `AI_MAX_ATTEMPTS`.

## Verdict et priorité

Les valeurs sont définies dans [`Verdict`](../../src/Analysis/Verdict.php) et [`Priority`](../../src/Analysis/Priority.php) ; les libellés russes sont produits par [`VacancyResultFormatter::format()`](../../src/Application/VacancyResultFormatter.php).

| Valeur | Texte Telegram (russe) | Interprétation |
| --- | --- | --- |
| `apply` | Откликаться | Envisager de postuler. |
| `borderline` | Погранично | Des doutes ou des points importants restent à clarifier. |
| `skip` | Пропустить | Ne pas retenir l’offre selon les critères définis. |
| `high` | высокий | Priorité élevée. |
| `medium` | средний | Priorité moyenne. |
| `low` | низкий | Priorité faible. |

L’application valide les valeurs autorisées, sans imposer de seuil numérique ni de combinaison précise de verdict et de priorité. Vous définissez les critères dans `instructions.md` ; **la décision revient au modèle**. Le verdict n’envoie aucune candidature à l’employeur.

## Notification Telegram

Le [formateur](../../src/Application/VacancyResultFormatter.php) produit un message texte ordinaire. Voici un modèle de disposition avec espaces réservés, **et non un résultat réel**. Les libellés restent russes, comme les messages de l’application :

```text
Откликаться / Погранично / Пропустить
Приоритет: высокий / средний / низкий
{компания} — {должность}
{краткое обоснование}
География и формат: {география и формат}
Оплата: {оплата}
Совпадения: {пункты через точку с запятой}
Риски: {пункты через точку с запятой}
Уточнить: {пункты через точку с запятой}
Источник: {ссылка с пометкой или «ссылка недоступна»}
Сообщение в чате: {ID входящего сообщения}
```

Les listes vides `matches`, `risks` et `missing` sont omises. L’ID de la dernière ligne désigne votre message reçu par le bot, et non la publication d’origine dans un canal.

Si le message dépasse 4 096 unités de code UTF-16, il est raccourci avec la mention `(сокращено для Telegram)`. L’entreprise, le poste et la justification reçoivent leurs propres libellés ; le verdict, la priorité et la source sont conservés. Les émojis ne sont pas coupés au milieu d’un caractère. L’évaluation complète reste dans SQLite.

## Source et fiabilité

Les liens proviennent du texte de l’offre, pas de la réponse du modèle. L’application n’ouvre pas les sites et ne télécharge pas les pages.

- Sans URL valable, elle affiche `Источник: ссылка недоступна` (« Source : lien indisponible »).
- Pour un lien HTTP/HTTPS trouvé, elle affiche `ссылка из объявления (не проверена)` (« lien provenant de l’annonce, non vérifié »).
- Pour un lien déjà présent du type `https://t.me/c/…/…`, dont les ID numériques correspondent aux métadonnées de la publication transférée depuis un canal, elle affiche `совпадает с ID пересланной публикации` (« correspond à l’ID de la publication transférée »).

La sélection et la vérification précises figurent dans [`sourceDescription()` et `verifiedSource()`](../../src/Application/VacancyResultFormatter.php). La concordance des ID confirme le lien avec le transfert, **pas** la véracité de l’offre ni la fiabilité de l’employeur. Aucune URL n’est créée à partir des seules métadonnées.

Dans la réponse du modèle, `source_status` doit toujours valoir `unavailable`. Lors de la sauvegarde par [`AnalysisResult::toJson()`](../../src/Analysis/AnalysisResult.php), l’application définit elle-même `verified_origin_ids` si la concordance URL/ID décrite est confirmée ; sinon elle conserve `unavailable`. Le modèle ne peut pas s’attribuer un statut vérifié.

## Notifications de traitement

[`IncomingUpdateProcessor`](../../src/Application/IncomingUpdateProcessor.php) et [`VacancyAnalysisPipeline`](../../src/Application/VacancyAnalysisPipeline.php) séparent la réception du traitement.

| Événement | Message ou état |
| --- | --- |
| Nouvelle offre enregistrée | `Вакансия сохранена.` |
| Publication connue transférée à nouveau | `Эта публикация уже сохранена.` |
| Début de la requête IA | `Вакансия передана на анализ ИИ. Результат придёт сюда, как только анализ завершится.` |
| Rejet exact par le préfiltre | `Пропустить` et le motif de correspondance exact, sans évaluation IA ni notification de démarrage. |
| Échec définitif de l’analyse | `Не удалось обработать` et `Техническая ошибка анализа. Исходная вакансия сохранена.`, suivis de la source et de l’ID du message. |
| Échec définitif de l’envoi | `delivery_status = failed` dans SQLite ; aucune notification supplémentaire d’échec de livraison. |

## Déroulement du traitement

1. **Enregistrement.** L’application stocke l’offre et la tâche dans SQLite. Après l’écriture réussie, le bot envoie `Вакансия сохранена.`. Une livraison répétée de la même mise à jour ne crée pas de seconde offre.
2. **Début de l’analyse.** Le bot tente une seule fois d’envoyer l’avis de démarrage. L’analyse continue même si cet avis n’arrive pas.
3. **Évaluation.** Le modèle confronte l’offre au profil et aux instructions. L’évaluation terminée est sauvegardée dans SQLite **avant** l’envoi du résultat à Telegram.
4. **Envoi.** Le bot transmet le résultat enregistré. En cas d’erreur temporaire, il peut réessayer **sans rappeler le modèle**.
5. **Reprise.** Après un redémarrage, les tâches inachevées reprennent en tenant compte des tentatives déjà utilisées. Une tâche dont toutes les tentatives sont épuisées n’est pas relancée automatiquement.

Si une panne réseau survient pendant l’envoi, Telegram peut avoir reçu le message alors que l’application n’a pas reçu de confirmation ; le résultat peut donc être envoyé en double. Les détails des nouvelles tentatives, du curseur et de l’enregistrement figurent dans le [guide de traitement](../guides/message-processing_fr.md). L’implémentation est dans [`VacancyAnalysisPipeline`](../../src/Application/VacancyAnalysisPipeline.php).
