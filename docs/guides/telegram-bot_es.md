# Configuración del bot de Telegram

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](telegram-bot.md) | [English](telegram-bot_en.md) | **Seleccionado** | [中文](telegram-bot_zh.md) | [Français](telegram-bot_fr.md) | [Deutsch](telegram-bot_de.md) |

[← Volver al README](../readme/README_es.md)

Necesitas un bot creado mediante BotFather. `TELEGRAM_ALLOWED_USER_ID` contiene el ID numérico de la cuenta de Telegram cuyas ofertas acepta la aplicación y a la que envía los resultados.

## Crea el bot

1. Abre [BotFather](https://t.me/BotFather) en Telegram y envía `/newbot`.
2. Sigue las indicaciones para elegir el nombre visible y el nombre de usuario del bot.
3. Guarda el token recibido en el archivo privado `config/bot.ini`, creado a partir del [ejemplo](../../config/bot.example.ini).
4. Abre el bot y envíale `/start`.

El token permite acceder al bot. Consérvalo en el archivo INI; no lo incluyas en comandos, capturas, Issues ni ejemplos públicos. Si se filtra, genera otro mediante BotFather, actualiza la configuración y reinicia el procesamiento. La [guía oficial de BotFather](https://core.telegram.org/bots/features#botfather) explica cómo administrar los bots.

## Averigua tu ID

Para localizar tu ID por primera vez, mantén `TELEGRAM_RECEIVE_MODE = "polling"`. El token ya debe estar configurado; el ID del usuario, el perfil y los parámetros del modelo todavía no son necesarios.

**En la aplicación local:** ejecuta `./vacancy-monitor` en Linux/macOS o `.\vacancy-monitor.exe` en PowerShell. Selecciona la opción para obtener el Telegram User ID y después la opción de comprobarlo ahora. Los menús existen en ruso e inglés; no hay una interfaz española.

**Al ejecutar desde el código fuente:** desde la raíz del proyecto, ejecuta:

```bash
# Muestra los ID de las cuentas que enviaron /start.
php bin/bot.php identify
```

Después de la comprobación aparecerá el ID numérico de una cuenta que envió `/start`. Guarda **tu propio ID** en `TELEGRAM_ALLOWED_USER_ID` dentro de `config/bot.ini`. No sirven un nombre precedido por `@` ni el ID del bot.

Si no aparece ningún ID, vuelve a enviar `/start` y repite la comprobación. Los mensajes reenviados, las descripciones de archivos adjuntos y los textos con caracteres adicionales (como `/starter` o `/start тест`) no se consideran el comando de configuración.

Si aparecen varios ID, **no elijas al azar**. En una instalación desde fuentes, ejecuta `php bin/bot.php identify` y guarda la salida. Después envía un nuevo `/start` al bot y ejecuta otra vez el comando. Si las líneas anteriores permanecen y al final aparece una nueva línea `ID отправителя /start: ...`, corresponderá a tu mensaje *si nadie más envió `/start` durante ese intervalo*. Si la salida no cambia o llegan comandos ajenos, este método no permite identificar tu ID con seguridad: `getUpdates` devuelve como máximo 100 actualizaciones pendientes. El menú muestra únicamente los ID únicos sin datos de los remitentes. No adivines el ID ni borres la cola de actualizaciones para averiguarlo.

La búsqueda realiza una sola solicitud `getUpdates`, no confirma actualizaciones ni guarda el ID en la base de datos. `identify` comparte el bloqueo no bloqueante `var/bot.lock` con `run`; si está ocupado, no se realiza ninguna solicitud a Bot API. Para la primera búsqueda solo se necesitan el token y el modo `polling`. El comando puede crear el directorio privado `var/` y el archivo de bloqueo, pero no abre SQLite. Si hay un webhook registrado, [cambia primero de modo](vds-webhook_es.md#cambio-de-modo). La lógica está en [`bin/bot.php`](../../bin/bot.php).

## Completa la configuración

Prepara el [perfil y las instrucciones](../../config/profile/README_es.md), después [conecta un modelo de IA](ai-models_es.md). Inicia el seguimiento local o configura [webhook y cron](vds-webhook_es.md).

Para obtener el ID, envía `/start` y ejecuta la comprobación. Cuando el seguimiento ya está activo, el bot responde a ese comando invitándote a reenviar una oferta. Los mensajes que solo empiezan por `/start` se tratan como texto ordinario de una oferta enviada por el usuario autorizado.

## Cómo enviar ofertas

- Envía al bot el texto de una oferta como mensaje normal.
- O reenvía al bot una publicación de un chat o canal.
- También puede procesarse el texto de la descripción de un archivo adjunto. Las imágenes y notas de voz no se reconocen por sí mismas.

El bot confirma que se ha guardado la oferta y luego envía la evaluación. Consulta [duplicados y reenvíos](message-processing_es.md) y [cómo leer el resultado](../reference/analysis-result_es.md). Si no recibes mensajes, revisa la [solución de problemas](troubleshooting_es.md).
