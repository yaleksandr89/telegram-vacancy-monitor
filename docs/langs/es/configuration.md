# Configuración

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/configuration.md) | [English](../en/configuration.md) | **Seleccionado** | [中文](../zh/configuration.md) | [Français](../fr/configuration.md) | [Deutsch](../de/configuration.md) |

[← Volver al README](README.md)

La configuración privada está en `config/bot.ini`, respecto a la raíz de la aplicación. Créala a partir de [`config/bot.example.ini`](../../../config/bot.example.ini), sin añadir secretos al ejemplo público. Los parámetros se definen en este archivo INI.

El archivo se lee mediante el analizador INI integrado, sin convertir textos en valores booleanos. Conserva las comillas, escribe los números como enteros decimales y utiliza `[]` para los elementos de listas repetibles. Reinicia el seguimiento local después de editarlo; una nueva ejecución de `work-once` y cada petición webhook nueva leen el archivo vigente.

## Modos y comandos

Los comandos se definen en [`bin/bot.php`](../../../bin/bot.php). Si no se indica ninguno, se ejecuta `run`.

| Comando | Modo | Requisitos |
| --- | --- | --- |
| `php bin/bot.php identify` | `polling` | Token del bot y bloqueo `var/bot.lock` disponible; no requiere perfil, ID ni ajustes de IA. |
| `php bin/bot.php run` | `polling` | Token del bot, Telegram ID del usuario, perfil, instrucciones, configuración de IA y acceso al almacenamiento. |
| `php bin/bot.php work-once` | `webhook` | Token, Telegram ID, secreto webhook, perfil, instrucciones, configuración de IA y almacenamiento. |
| `php bin/bot.php webhook set <https-url>` | Administración webhook | Token, `TELEGRAM_RECEIVE_MODE=webhook`, `TELEGRAM_WEBHOOK_SECRET` válido y dirección HTTPS pública. |
| `php bin/bot.php webhook info` | Administración webhook | Solo token; no requiere ID, perfil, IA ni modo de recepción. |
| `php bin/bot.php webhook delete` | Administración webhook | Solo token; no requiere ID, perfil, IA ni modo de recepción. |
| `public/webhook.php` mediante FPM | `webhook` | Token, Telegram ID, secreto webhook y base de datos preparada; la petición HTTP no carga IA ni perfil. |

`webhook set` acepta una dirección HTTPS con nombre DNS público y puerto 443, 80, 88 u 8443. Rechaza URLs con credenciales, query o fragment. `webhook info` muestra el registro, el número de actualizaciones pendientes y el último error, **pero no imprime la URL registrada**. `webhook delete` conserva las actualizaciones pendientes (`drop_pending_updates=false`). Estos comandos no abren SQLite ni modifican `config/bot.ini`; cambiar el modo no registra ningún webhook.

En los archivos de la aplicación local, el menú ejecuta `identify` y `run` utilizando el PHP incluido; no hace falta PHP del sistema. `work-once` y la administración webhook se explican para la instalación desde fuentes en servidor. `identify` adquiere el bloqueo no bloqueante antes de contactar Bot API; en una primera comprobación puede crear únicamente el directorio privado `var/` y `bot.lock`.

## Telegram

| Clave | Propósito y valores admitidos | Obligatorio / predeterminado |
| --- | --- | --- |
| `TELEGRAM_BOT_TOKEN` | Token emitido por BotFather con formato «dígitos:letras/dígitos/`_`/`-`». Un formato válido no demuestra que el token funcione. | Obligatorio para cualquier interacción con el bot; vacío en el ejemplo. |
| `TELEGRAM_ALLOWED_USER_ID` | ID numérico de la cuenta Telegram cuyas ofertas acepta el bot y a la que envía resultados. Se obtiene con `identify` o mediante el menú después de `/start`. | Obligatorio para `run`, `work-once` y recepción webhook; no para `identify` ni administración webhook; vacío en el ejemplo. |
| `TELEGRAM_RECEIVE_MODE` | `polling` para `run`/`identify` locales; `webhook` para FPM, `work-once` y `webhook set`. | Opcional; si falta, `polling`. |
| `TELEGRAM_WEBHOOK_SECRET` | Token secreto que autentica peticiones webhook entrantes: 1–256 caracteres entre `A–Z`, `a–z`, `0–9`, `_` y `-`. Coincide con `secret_token` de `webhook set`. | Obligatorio para recepción webhook, `work-once` y `webhook set`; no para `webhook info/delete`; vacío en el ejemplo. |

Elegir `TELEGRAM_RECEIVE_MODE` no registra ni elimina un webhook; utiliza los comandos `webhook set/info/delete`. La validación HTTP está en [`WebhookReceiver`](../../../src/Application/WebhookReceiver.php); [`public/webhook.php`](../../../public/webhook.php) carga sus ajustes. El procedimiento se explica en la [guía del servidor](vds-webhook.md#cambio-de-modo).

## Conexión de IA

La conexión con el modelo se configura en `config/bot.ini`. [`OpenAiCompatibleEvaluator`](../../../src/Analysis/OpenAiCompatibleEvaluator.php) valida los requisitos.

| Clave | Propósito y valores admitidos | Obligatorio / predeterminado |
| --- | --- | --- |
| `AI_PROVIDER_LOCATION` | `local` o `cloud`; determina las direcciones permitidas y si hace falta clave. | Opcional; predeterminado `local`. |
| `AI_BASE_URL` | URL base sin `/chat/completions`. HTTP/HTTPS, sin usuario, contraseña, query, fragment, espacios ni caracteres de control; máximo 2048 bytes. | Obligatoria, sin URL alternativa; ejemplo `http://127.0.0.1:1234/v1`. |
| `AI_MODEL` | Identificador no vacío del modelo API, UTF-8, máximo 200 bytes y sin caracteres de control ASCII. | Obligatorio; vacío en el ejemplo. |
| `AI_API_KEY` | Clave para `Authorization: Bearer`, UTF-8, máximo 4096 bytes, sin caracteres de control ASCII. | Opcional para `local` si el servidor no la exige; obligatoria y no vacía para `cloud`; vacía en el ejemplo. |
| `AI_MAX_ATTEMPTS` | Entero de 1 a 5: intentos totales, incluido el inicial, por separado para análisis y entrega. | Opcional; predeterminado `3`. |
| `AI_REQUEST_TIMEOUT_SECONDS` | Entero 30–1800: límite en segundos de una petición de IA. | Opcional; predeterminado `180`; el valor vacío no se reemplaza por el predeterminado. |

Para `local` se permiten `localhost`, `[::1]` y rangos IPv4 `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12` y `192.168.0.0/16`. No sirven nombres de red locales ni otras direcciones IPv6. Para `cloud` son obligatorios HTTPS y una clave no vacía.

El tiempo de conexión es de 5 segundos y no se configura aparte. Se desactivan las redirecciones HTTP y los proxies para las peticiones de IA y se verifica TLS. [`CurlAiTransport`](../../../src/Analysis/Http/CurlAiTransport.php) impone estas restricciones. Los errores temporales permiten reintentos con espera; los permanentes detienen el procesamiento sin consumir los demás intentos. Consulta la [guía de modelos](ai-models.md#tiempos-de-espera-y-reintentos).

## Prefiltro

El prefiltro omite mensajes cortos predefinidos sin consultar a la IA. Ambas listas están vacías de forma predeterminada. [`ConservativePrefilter`](../../../src/Analysis/ConservativePrefilter.php) aplica las reglas.

| Clave | Propósito |
| --- | --- |
| `AI_PREFILTER_EXACT_REJECT_LINES[]` | Texto exacto del mensaje completo que puede rechazarse directamente. |
| `AI_PREFILTER_AMBIGUITY_TERMS[]` | Palabra o frase cuya presencia **anula un rechazo exacto**. |

### Una frase

```ini
; Rechazar solo si coincide el mensaje completo.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
```

El mensaje formado únicamente por `Только ночная смена.` se omite sin análisis tras quitar los espacios exteriores. `Только ночная смена. Есть варианты?` ya no coincide por completo y se envía al modelo.

### Varias frases y excepciones

Cada frase ocupa **una línea propia**. Este ejemplo contiene tres reglas exactas y dos términos de ambigüedad:

```ini
; Rechazos exactos y términos que los anulan.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

Una coincidencia completa con alguna de las tres frases provoca el rechazo. Si aparece cualquiera de los dos términos de ambigüedad, se anula y el texto pasa al modelo. La coincidencia exacta distingue mayúsculas y minúsculas; los términos de ambigüedad se buscan sin distinguirlas.

Cada elemento de la lista contiene **una sola cadena no vacía**, no varias reglas separadas por comas. Los anuncios de varias líneas no se rechazan: se compara el mensaje entero, no una frase. No se usan expresiones regulares.

## Perfil y almacenamiento

Los siguientes archivos no tienen claves INI: sus rutas las establece el [punto de entrada CLI](../../../bin/bot.php).

| Ruta | Propósito |
| --- | --- |
| `config/profile/local/candidate.md` | Perfil del candidato, obligatorio para procesar. |
| `config/profile/local/instructions.md` | Instrucciones del modelo, obligatorias para procesar. |
| `var/vacancies.sqlite` | Mensajes, resultados y cola. |
| `var/bot.lock` | Bloqueo compartido por `run`, `identify` y cron `work-once`; las peticiones webhook usan transacciones SQLite. |

Los límites de los archivos Markdown se describen en la [guía del perfil](profile.md). La configuración, el perfil y `var/` contienen datos privados, están excluidos de Git y deben conservarse al actualizar.
