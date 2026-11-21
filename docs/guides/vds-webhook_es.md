# Servidor: webhook y cron

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](vds-webhook.md) | [English](vds-webhook_en.md) | **Seleccionado** | [中文](vds-webhook_zh.md) | [Français](vds-webhook_fr.md) | [Deutsch](vds-webhook_de.md) |

[← Volver al README](../readme/README_es.md)

El servidor recibe ofertas por HTTPS, las guarda en SQLite y las procesa según una programación. Puedes enviar texto al bot o reenviarlo desde otro chat.

[`public/webhook.php`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/public/webhook.php) guarda los mensajes recibidos. `php bin/bot.php work-once` realiza la evaluación de IA y entrega resultados. Para un mismo token, utiliza **webhook o polling local**, nunca ambos a la vez.

## Preparar el entorno

Coloca los archivos del repositorio en un directorio permanente del servidor **fuera** de la carpeta pública del sitio.

Necesitas PHP `^8.5` para CLI y PHP-FPM, Composer, HTTPS con certificado válido y extensiones `curl`, `json`, `mbstring`, `PDO`, `pdo_sqlite`. Comprueba las extensiones por separado en CLI y FPM, porque sus ajustes pueden diferir. Los requisitos figuran en [`composer.json`](../../composer.json).

Desde la raíz de la instalación en servidor, ejecuta:

```bash
# Instala las dependencias del composer.lock del proyecto.
composer install
```

Usa el `composer.lock` del proyecto. Composer solo sirve para preparar la instalación, no para atender webhooks.

Configura el servidor HTTP y un grupo PHP-FPM específico con las herramientas de tu sistema:

- DocumentRoot debe apuntar **exclusivamente** a `public/`; el único punto PHP público es `/webhook.php`.
- Desactiva el listado de directorios, ejecución de otras rutas PHP y alias o rutas alternativas que expongan la raíz del proyecto.
- Nunca expongas `config/`, `vendor/`, `var/`, `.git/` ni fuentes por HTTP.
- Limita el cuerpo de petición a 256 KiB; la aplicación también comprueba el límite.
- Desactiva `display_errors` en FPM. No registres cuerpos de petición, tokens, cabeceras secretas ni textos de ofertas en los logs HTTP.

Las directivas concretas dependen del servidor HTTP. Antes del registro comprueba el HTTPS y que las rutas privadas no sean accesibles desde fuera.

## Crear configuración y perfil

Si `config/bot.ini` todavía no existe, copia el [ejemplo](../../config/bot.example.ini). Edita el archivo con un editor y no escribas tokens en comandos.

1. Crea el bot con [BotFather](https://t.me/BotFather) y guarda su token en `TELEGRAM_BOT_TOKEN`.
2. Mientras está seleccionado `polling` y no hay webhook registrado, envía `/start` al bot y ejecuta `php bin/bot.php identify`. Si `var/bot.lock` está ocupado, detén el otro polling.
3. Guarda tu ID en `TELEGRAM_ALLOWED_USER_ID`.
4. Prepara `config/profile/local/candidate.md` y `config/profile/local/instructions.md` según la [guía del perfil](../../config/profile/README_es.md).
5. Configura `AI_PROVIDER_LOCATION`, `AI_BASE_URL`, `AI_MODEL` y `AI_API_KEY` si hace falta. La [guía de modelos](ai-models_es.md) explica la conexión y la transmisión de datos.

Si el modelo funciona en el mismo VDS, `http://127.0.0.1:1234/v1` es válido en modo `local`. Si está en otro equipo, usa una IPv4 privada accesible desde el VDS. El modo cloud exige HTTPS y clave API.

Después de determinar el ID, modifica la configuración:

```ini
; Activa webhook y reemplaza el secreto vacío por uno generado.
TELEGRAM_RECEIVE_MODE = "webhook"
TELEGRAM_WEBHOOK_SECRET = ""
```

Sustituye el secreto vacío por un valor aleatorio de 1–256 caracteres `A–Z`, `a–z`, `0–9`, `_`, `-`. Puedes generar 64 caracteres con PHP:

```bash
# Genera el secreto sin incluirlo en el historial de la consola.
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Copia el resultado a `TELEGRAM_WEBHOOK_SECRET` en `config/bot.ini` privado; no lo publiques en logs ni capturas. `webhook set` lo enviará a Telegram como `secret_token`; **no** lo añadas a la URL.

Todos los parámetros están en la [referencia de configuración](../reference/configuration_es.md).

## Permisos y preparación de SQLite

CLI y FPM deben utilizar **la misma ruta absoluta de instalación** y una única base `var/vacancies.sqlite`. Es preferible una cuenta de aplicación sin privilegios para cron y el grupo PHP-FPM.

Necesita leer código, `vendor/`, INI y perfil y escribir en BD y `var/`: SQLite crea allí su diario y CLI el `bot.lock`. No des acceso a otros usuarios. Si CLI y FPM usan cuentas distintas, configura ACL específicas, también heredables para nuevos archivos. CLI/FPM usan `umask(0077)`, por lo que un grupo compartido no basta.

Comprueba permisos con **ambas** identidades. No hagas `chown` ni `chmod` recursivos indiscriminados por todo el proyecto.

Antes de registrar el webhook ejecuta como usuario de cron:

```bash
# Crea el esquema de BD y puede procesar tareas pendientes: NO es dry-run.
php bin/bot.php work-once
```

Usa la versión correcta de PHP CLI. La orden valida configuración y perfil, crea `var/` y el esquema si es necesario y **procesa las tareas que ya estén pendientes**. Después de crear la BD el webhook puede recibir mensajes.

Antes de actualizar, con los procesos detenidos, haz copias privadas del INI, perfil y SQLite. No sustituyas la base existente por una vacía ni elimines la cola para probar.

## Registrar webhook

Comprueba que Telegram pueda llegar por HTTPS a `/webhook.php`. Sin cabecera secreta recibe HTTP 403 y con GET recibe 405. Antes del registro detén todo `run` e `identify` de ese bot: no se puede usar polling y webhook simultáneamente.

En la instalación desde fuentes, utiliza los comandos propios `php bin/bot.php webhook`. Leen token y secreto (cuando se requiere) del `config/bot.ini` privado, sin introducir secretos en consola.

| Comando | Función |
| --- | --- |
| `php bin/bot.php webhook set <https-url>` | Registra la URL HTTPS indicada; exige modo `webhook` y secreto válido en INI. |
| `php bin/bot.php webhook info` | Estado del registro, número de actualizaciones pendientes y datos del último error. |
| `php bin/bot.php webhook delete` | Cancela registro sin borrar actualizaciones pendientes. |

La implementación utiliza métodos oficiales [setWebhook](https://core.telegram.org/bots/api#setwebhook), [getWebhookInfo](https://core.telegram.org/bots/api#getwebhookinfo), [deleteWebhook](https://core.telegram.org/bots/api#deletewebhook). El registro utiliza `allowed_updates=["message"]`, `drop_pending_updates=false`, `max_connections=1`. El borrado también usa `drop_pending_updates=false`.

### Administración del webhook

Una vez definidos `TELEGRAM_RECEIVE_MODE = "webhook"` y `TELEGRAM_WEBHOOK_SECRET` no vacío, ejecuta desde la raíz del proyecto (sustituye la URL por **tu dominio público HTTPS real**):

```bash
# Registra tu dirección HTTPS y consulta el estado.
php bin/bot.php webhook set 'https://bot.example.org/webhook.php'
php bin/bot.php webhook info
```

La URL de `set` exige DNS público y HTTPS por puerto 443, 80, 88 o 8443. No admite credenciales, query ni fragment. Si funciona, `set` muestra `Webhook зарегистрирован.`. `info` informa sobre registro, pendientes y errores, **pero no muestra la URL registrada ni la compara con la indicada**.

Después de verificar, puedes habilitar cron. Para eliminar el registro:

```bash
# Elimina registro sin borrar pendientes y comprueba el resultado.
php bin/bot.php webhook delete
php bin/bot.php webhook info
```

Tras `delete`, se espera `Webhook удалён. Ожидающие обновления сохранены.` y luego `Webhook не зарегистрирован.`. `info` y `delete` solo requieren token válido; funcionan incluso si está seleccionado `polling`. No necesitan SQLite, perfil ni ajustes de IA. No crees scripts PHP provisionales que llamen a Bot API independientemente.

## Ejecutar procesamiento mediante cron

Programa la ejecución una vez por minuto con la cuenta asignada. Reemplaza **ambas** rutas absolutas:

```cron
# Una ejecución por minuto; reemplaza ambas rutas absolutas.
* * * * * /absolute/path/to/php8.5 /absolute/path/to/project/bin/bot.php work-once
```

El directorio de trabajo de cron no importa: el [punto de entrada CLI](../../bin/bot.php) resuelve rutas según su propia ubicación. Dirige la salida solo a logs privados o correo del administrador, no a una carpeta pública.

La orden toma el bloqueo compartido `var/bot.lock`, recupera intentos interrumpidos y procesa hasta 10 lotes. Transcurridos 20 segundos no se inician nuevos lotes, pero una petición iniciada puede durar `AI_REQUEST_TIMEOUT_SECONDS` (30–1800 segundos). Un trabajador ocupado hace saltar la siguiente ejecución, en lugar de permitir análisis paralelos. Lo define [`BotRunner::workOnce()`](../../src/Application/BotRunner.php).

Envía al bot una oferta ficticia breve. Primero espera `Вакансия сохранена.`, luego, al ejecutarse cron, el aviso de inicio y el resultado. Los avisos de guardado e inicio pueden perderse por fallo de red sin que desaparezca el mensaje almacenado.

## Recepción y errores de entrega

[`WebhookReceiver`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/src/Application/WebhookReceiver.php) verifica `X-Telegram-Bot-Api-Secret-Token` **antes** de analizar JSON o abrir SQLite. Actualización, mensaje y tarea se guardan en una transacción. HTTP 204 llega después de confirmar los datos en SQLite. Repetir un mismo `update_id` no crea otra tarea; las actualizaciones pueden llegar desordenadas.

| HTTP | Qué hacer |
| --- | --- |
| 400 | Comprobar JSON y campos obligatorios de petición. |
| 403 | Comparar secreto de INI y registro; verificar que la cabecera alcanza FPM. |
| 405 | Usar POST; GET sirve solo para comprobar rechazo. |
| 413 | Revisar tamaño del cuerpo: máximo 256 KiB. |
| 415 | Indicar `Content-Type: application/json`. |
| 503 | Revisar modo, configuración, autoload Composer, existencia de BD y permisos SQLite. |

SQLite espera hasta 5 segundos por el bloqueo. Un error de escritura produce 503 para permitir a Telegram reintentar, aunque esos reintentos son limitados: corrige la causa pronto. La confirmación solo llega tras guardar en SQLite. Un `update_id` repetido no crea otra tarea. Consulta [tratamiento de duplicados](message-processing_es.md).

El aviso de inicio se intenta solo una vez por oferta. La evaluación completa se guarda antes de enviar y no se recalcula si falla el envío. `AI_MAX_ATTEMPTS` se aplica por separado a análisis y entrega. Las tareas definitivamente fallidas no se crean de nuevo automáticamente. Ver [formatos y estados](../reference/analysis-result_es.md).

## Cambio de modo

**De aplicación local a servidor:**

1. Detén supervisión y cualquier `identify`. Espera a que acabe el procesamiento ya iniciado.
2. Con todos los procesos parados, mueve `config/bot.ini`, perfil y **todo `var/`**, incluida SQLite existente, al servidor. No ejecutes dos copias con un token y datos divergentes.
3. Configura PHP-FPM, HTTPS, permisos y cron, pero **todavía no actives cron ni registres webhook**. Asegúrate de que CLI/FPM comparten BD.
4. Define `TELEGRAM_RECEIVE_MODE = "webhook"` y secreto. Ejecuta `php bin/bot.php work-once` como usuario cron para preparar el esquema. **Esto no es dry-run:** con tareas pendientes puede invocar la IA y enviar mensajes. Si aún no quieres procesar la cola, no lo ejecutes solo para comprobar ajustes.
5. Registra tu URL mediante `php bin/bot.php webhook set 'https://bot.example.org/webhook.php'` cambiando el ejemplo por tu dominio. Comprueba `webhook info` y habilita cron.

Usa una sola copia actual de datos durante el cambio. Migrar la BD no recupera las actualizaciones ya confirmadas o purgadas por Telegram.

**Del servidor a la aplicación local:**

1. Desactiva cron y espera a que termine cualquier trabajador activo.
2. Ejecuta `php bin/bot.php webhook delete` (conserva pendientes); verifica `Webhook не зарегистрирован.` con `php bin/bot.php webhook info`.
3. Espera las peticiones PHP-FPM iniciadas y desactiva el controlador público webhook para que deje de escribir en BD.
4. Con todos los controladores parados, conserva la misma BD y archivos privados, copiando todo `var/` al trasladar la instalación.
5. Pon `TELEGRAM_RECEIVE_MODE = "polling"` e inicia la app local o `php bin/bot.php run` desde fuentes.

No limpies manualmente cursor, tabla de actualizaciones recibidas ni mensajes. Tras una pausa larga, polling restablece un `offset` de salida no fiable sin borrar datos. `run` e `identify` no funcionan con `webhook` activo; cambiar INI no registra ni elimina webhook: usa `webhook set` y `webhook delete`.

Ante fallos consulta [ayuda local](local-gum_es.md#si-algo-falla) y [solución detallada](troubleshooting_es.md).
