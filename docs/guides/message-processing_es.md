# Cómo recibe y procesa ofertas la aplicación

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](message-processing.md) | [English](message-processing_en.md) | **Seleccionado** | [中文](message-processing_zh.md) | [Français](message-processing_fr.md) | [Deutsch](message-processing_de.md) |

[← Volver al README](../readme/README_es.md)

Cuando envías o reenvías una oferta al bot, Telegram entrega una actualización a la aplicación. Esta guarda el texto y crea una tarea de evaluación. Después del análisis, el resultado se envía al mismo chat.

## Qué se guarda

Para cada oferta, SQLite guarda el texto, los identificadores del mensaje y, si están disponibles, los datos de la publicación reenviada originalmente. Los estados de la evaluación y de su envío se almacenan por separado.

En el [esquema de la base de datos](../../src/Storage/SqliteSchema.php) esto corresponde a las tablas `messages` y `analysis_jobs`.

## Para qué sirve el cursor

En modo local, la aplicación recibe actualizaciones mediante `getUpdates`. Cada una tiene un `update_id` numérico. El **cursor** guarda la siguiente posición tras la última actualización procesada y confirmada de forma fiable. Antes de cada petición, la aplicación comprueba si esa posición sigue siendo válida.

Ejemplo:

1. La aplicación recibe la actualización `104` con una oferta nueva.
2. Guarda la oferta en SQLite, crea una tarea y registra el siguiente cursor, `105`.
3. La próxima petición a Telegram comienza con `offset = 105`; ya no es necesario recibir `104` de nuevo.

Guardar la oferta y avanzar el cursor forman una misma operación. Si falla la escritura, el cursor no cambia y Telegram puede volver a enviar la actualización. Si esta ya estaba guardada, no se crea una segunda copia.

### Campos del cursor

La tabla `cursor` contiene un registro para la recepción local:

| Columna | Ejemplo | Significado |
| --- | --- | --- |
| `name` | `main` | Nombre fijo del cursor; no es el nombre del bot ni del chat. |
| `next_offset` | `105` | Posición después del último `update_id` guardado; se comprueba antes de usarla. |
| `last_handled_at` | `1795000000` | Marca de tiempo Unix cuando el procesamiento se confirmó junto con el cursor. Tras migrar una base antigua puede ser `NULL`. |

**`next_offset` se calcula a partir de `update_id`, no del número del mensaje.** Después de la actualización `104` se guarda `105`. En una secuencia vigente, el cursor no disminuye; las respuestas vacías de `getUpdates` no modifican `last_handled_at`. La fecha y el cursor se confirman en la misma transacción que el mensaje y la tarea, si existen.

### Recuperación tras una inactividad prolongada

Telegram puede asignar nuevos `update_id` inferiores tras al menos una semana sin actualizaciones nuevas. Enviar el antiguo `offset` alto podría confirmar en Telegram Bot API actualizaciones con ID más bajos que todavía no se han procesado.

Por ello, antes de **cada** petición saliente `getUpdates`, la aplicación envía `offset = 0` cuando falta `last_handled_at`, es inválido, apunta al futuro o tiene más de cinco días. No borra el cursor anterior por anticipado. La primera actualización guardada correctamente establece la nueva posición, aunque su ID sea inferior al antiguo. Las restricciones de unicidad de SQLite siguen evitando ofertas y tareas duplicadas.

Si una respuesta nueva contiene ID inferiores al `offset` solicitado, o llegan actualizaciones fuera de orden, se detiene la recepción sin avanzar el cursor. Si falla la escritura en SQLite, cursor, fecha, mensaje y tarea se revierten juntos. El mecanismo no puede recuperar las actualizaciones ya confirmadas por Telegram ni las eliminadas al expirar su periodo de conservación (máximo 24 horas).

Los identificadores cumplen funciones distintas:

| Campo | Significado |
| --- | --- |
| `update_id` de la actualización de Telegram | Número del evento usado para calcular `next_offset`. |
| `recipient_message_id` de la tabla `messages` | Número del mensaje en el chat con el bot; no interviene en el cursor. |
| `origin_message_id` de la tabla `messages` | Número de la publicación original del canal, si Telegram lo proporciona al reenviar; no interviene en el cursor. |

Por ejemplo, la actualización `104` puede contener el mensaje `312` en el chat del bot y la publicación original `87` en un canal. El cursor pasa a `105`; los números `312` y `87` no lo afectan.

El cursor no identifica una oferta ni su fuente. Solo sirve para recibir actualizaciones por `getUpdates` en orden. Consulta [`SqliteInboxRepository::pollingOffset()` y `applyUpdate()`](../../src/Storage/SqliteInboxRepository.php).

## ¿Y si se utiliza webhook?

En modo webhook, Telegram envía peticiones al servidor. Pueden repetirse o llegar fuera de orden. En lugar del cursor de polling, la aplicación registra cada `update_id` en `received_updates` y comprueba si ya se recibió.

Por ejemplo, una petición con `update_id = 205` se guarda correctamente y Telegram recibe HTTP 204. Si llega de nuevo la misma petición, no se crea otra tarea. Si falla la escritura en la base, el servidor devuelve un error y Telegram puede reintentar la entrega.

La implementación está en [`SqliteInboxRepository::applyWebhookUpdate()`](../../src/Storage/SqliteInboxRepository.php) y [`WebhookReceiver`](../../src/Application/WebhookReceiver.php).

## Qué ofertas se consideran duplicadas

La aplicación utiliza dos tipos de identificadores:

- **Mensaje en el chat del bot.** Una entrega repetida del mismo mensaje con el mismo ID no crea otra oferta.
- **Publicación reenviada originalmente.** Si Telegram proporciona los identificadores del canal y de la publicación de origen, volver a reenviar esa publicación no genera otra evaluación aunque el nuevo mensaje en el chat del bot tenga un ID diferente.

Por ejemplo, reenvías hoy una oferta de un canal y mañana la vuelves a reenviar. Si se conservaron los identificadores originales, la aplicación responde `Эта публикация уже сохранена.` («Esta publicación ya está guardada»). **El texto idéntico por sí solo no se considera duplicado:** dos anuncios distintos pueden contener exactamente el mismo texto.

Las reglas están en [`IncomingUpdateProcessor`](../../src/Application/IncomingUpdateProcessor.php) y las claves únicas de los mensajes, en [`SqliteSchema`](../../src/Storage/SqliteSchema.php).

## Qué sucede después de guardar

1. El bot confirma con `Вакансия сохранена.` («Oferta guardada»).
2. La aplicación analiza el texto guardado usando el perfil y las instrucciones. En el servidor lo hace un proceso iniciado por cron.
3. La evaluación completa se guarda en SQLite y se envía a Telegram.
4. Si el envío falla temporalmente, la aplicación reintenta **enviar el resultado ya guardado** sin solicitar una evaluación nueva. Si Bot API devuelve HTTP 429, se utiliza un `parameters.retry_after` válido cuando supera la espera local.
5. Si el proceso se interrumpe, el trabajo pendiente continúa en la próxima ejecución, respetando los intentos restantes.

La notificación del comienzo del análisis puede no llegar por un fallo de red aunque la evaluación continúe. Cuando se agotan todos los intentos, el análisis no se reinicia automáticamente. En el caso poco frecuente de que Telegram reciba el mensaje final pero su confirmación no llegue a la aplicación, la notificación final puede repetirse.

[`SqliteAnalysisRepository`](../../src/Storage/SqliteAnalysisRepository.php) guarda los estados de análisis y envío; [`VacancyAnalysisPipeline`](../../src/Application/VacancyAnalysisPipeline.php) ejecuta las operaciones. Consulta el [formato de evaluación y notificaciones](../reference/analysis-result_es.md).
