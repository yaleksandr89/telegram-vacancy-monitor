# Política de seguridad

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/.github/SECURITY.md) | [English](../en/SECURITY.md) | **Seleccionado** | [中文](../zh/SECURITY.md) | [Français](../fr/SECURITY.md) | [Deutsch](../de/SECURITY.md) |

[← Volver al README](README.md)

## Comunicar una vulnerabilidad

Para comunicarla de forma confidencial, utiliza [GitHub Security Advisories](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/advisories) y la opción **Report a vulnerability**. Si el formulario privado no está disponible, abre un [Issue](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) solicitando un canal privado sin describir la vulnerabilidad.

## Ámbito de seguridad

Entre los problemas de seguridad se encuentran:

- exposición de tokens, claves de API y configuración privada;
- evasión de la verificación del remitente autorizado o de la protección del webhook;
- acceso no autorizado a ofertas, perfiles de candidatos, resultados de análisis y datos de SQLite;
- procesamiento inseguro de mensajes no confiables o respuestas del modelo de IA;
- pérdida de integridad de las aplicaciones distribuidas.

## Contenido del informe

Indica la versión o el commit afectado, el posible impacto y los pasos para reproducir el problema. Antes de enviarlo, elimina secretos activos, conversaciones privadas y datos personales.

## Divulgación

Acuerda la publicación de los detalles técnicos con el responsable del proyecto para que pueda preparar una corrección antes de divulgar la vulnerabilidad.
