# Exemples d’évaluation d’offres

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/vacancy-analysis.md) | [English](../en/vacancy-analysis.md) | [Español](../es/vacancy-analysis.md) | [中文](../zh/vacancy-analysis.md) | **Sélectionné** | [Deutsch](../de/vacancy-analysis.md) |

[← Retour au README](README.md)

Deux exemples indépendants montrent comment définir un profil de candidat, des consignes d’évaluation et le texte d’une offre. Toutes les données d’entrée sont fictives ; le résultat dépend du modèle sélectionné et des règles configurées.

- [Développeur Go](go-developer.md) — développement serveur, exigences d’expérience, astreintes et rémunération.
- [Analyste produit](analyst.md) — SQL, études, expérimentations et conditions de travail.

## Utilisation des exemples

1. Enregistrez le profil choisi dans `config/profile/local/candidate.md` et les consignes dans `config/profile/local/instructions.md`.
2. [Connectez un modèle](ai-models.md) et lancez la surveillance.
3. Envoyez au bot le texte de l’offre tiré de l’exemple correspondant.
4. Lisez la justification et les questions reçues. L’évaluation n’est pas fixée à l’avance.

La structure du résultat est détaillée dans la [référence](analysis-result.md).
