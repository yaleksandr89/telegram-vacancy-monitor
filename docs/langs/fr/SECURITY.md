# Politique de sécurité

## Choisir une langue

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/.github/SECURITY.md) | [English](../en/SECURITY.md) | [Español](../es/SECURITY.md) | [中文](../zh/SECURITY.md) | **Sélectionné** | [Deutsch](../de/SECURITY.md) |

[← Retour au README](README.md)

## Signaler une vulnérabilité

Pour un signalement confidentiel, utilisez [GitHub Security Advisories](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/advisories) et la fonction **Report a vulnerability**. Si le formulaire privé est indisponible, ouvrez une [Issue](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) pour demander un canal de contact privé, sans décrire la vulnérabilité.

## Périmètre de sécurité

Les problèmes de sécurité comprennent notamment :

- la divulgation de jetons, clés API et configurations privées;
- le contournement de la vérification de l’expéditeur autorisé ou de la protection du webhook;
- l’accès non autorisé aux offres, profils de candidats, résultats d’analyse ou données SQLite;
- le traitement non sécurisé de messages non fiables ou de réponses de modèles IA;
- l’atteinte à l’intégrité des applications distribuées.

## Contenu du signalement

Indiquez la version ou le commit affecté, les conséquences possibles et les étapes de reproduction. Supprimez les secrets actifs, conversations privées et données personnelles avant l’envoi.

## Divulgation

Concertez-vous avec le responsable du projet avant de publier les détails techniques, afin de permettre la préparation d’un correctif avant leur divulgation publique.
