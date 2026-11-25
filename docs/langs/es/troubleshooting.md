# Solución de problemas

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/troubleshooting.md) | [English](../en/troubleshooting.md) | **Seleccionado** | [中文](../zh/troubleshooting.md) | [Français](../fr/troubleshooting.md) | [Deutsch](../de/troubleshooting.md) |

[← Volver al README](README.md)

Comienza con «Проверить готовность» («Comprobar preparación») en la aplicación local y abre «Подробнее» («Detalles») para la categoría señalada. Esta comprobación no consulta Telegram ni la IA, no inspecciona el contenido del perfil y no escribe en SQLite. Que termine correctamente significa que las condiciones locales están listas; la conexión se comprueba enviando un texto de prueba al bot mientras está en marcha.

Los textos de la interfaz están en [mensajes de la IU](../../../src/ui/windows/messages.json); las comprobaciones y categorías, en [`inspect.php`](../../../src/ui/windows/inspect.php). Si solicitas ayuda, indica categoría, código de salida, sistema operativo y forma de inicio, pero nunca tokens ni textos privados del perfil.

## La aplicación no se abre

| Síntoma | Acción |
| --- | --- |
| «Файлы приложения отсутствуют или повреждены» (archivos dañados o ausentes) | Extrae de nuevo un archivo fiable en otra carpeta; conserva la estructura y migra solo INI, perfil y `var/` privados. |
| «Нужен интерактивный терминал» (se necesita terminal interactivo) | Abre un terminal normal sin redirigir entrada, salida ni errores. |
| «Среда PHP приложения неполна или устарела» (entorno PHP incompleto u obsoleto) | Comprueba que elegiste el archivo de tu SO/arquitectura y extrajiste todo `runtime/`. |
| No se pudo preparar el inicio o el diagnóstico | Comprueba el directorio temporal del sistema y permisos de ejecución. |
| Windows bloquea el script | Revisa la política PowerShell de la organización y acuerda el inicio con el administrador. |
| macOS bloquea una aplicación descargada | Revisa origen del archivo y motivo indicado en ajustes de seguridad macOS. |

Si se dañaron los archivos públicos, restaura todo el archivo: el [ejecutable](../../../src/launcher/main.go) verifica el manifiesto. El [manual local](local-gum.md#datos-y-actualizaciones) explica cómo actualizar sin perder datos privados.

La instalación desde fuentes requiere PHP `^8.5`, extensiones de [`composer.json`](../../../composer.json) y el autoload generado por Composer. Si falta `vendor/autoload.php`, instala las dependencias según `composer.lock`; no necesitas modificar el perfil ni SQLite.

## Configuración o perfil incompletos

- Crea `config/bot.ini` desde el [ejemplo](../../../config/bot.example.ini). Asegúrate de que el editor no añadió `.txt` al nombre.
- Revisa comillas y nombres de claves; las listas del prefiltro usan claves repetidas con `[]`.
- Indica token, ID de tu propia cuenta en `TELEGRAM_ALLOWED_USER_ID`, dirección e identificador del modelo. En la nube también necesitas clave API.
- Crea ambos archivos `config/profile/local/candidate.md` y `config/profile/local/instructions.md`.
- Deben ser archivos normales no vacíos, UTF-8 válido, sin enlaces simbólicos, con máximo 32 768 bytes y 16 000 caracteres cada uno. Se permiten tabulaciones y saltos de línea; no otros caracteres de control.

Si la comprobación inicial permite un archivo pero el inicio lo rechaza, revisa codificación, longitud y contenido: el menú solo verifica accesibilidad y tamaño. Consulta la [guía del perfil](profile.md) y la [referencia de configuración](configuration.md).

Tras editar, reinicia la supervisión local. En el servidor, el siguiente `work-once` carga los archivos nuevos.

## No se puede obtener el ID

Envía `/start` sin texto adicional y pulsa otra vez «Проверить сейчас» («Comprobar ahora»), o ejecuta `php bin/bot.php identify` desde las fuentes. Un comando reenviado o `/start` con parámetros no sirve. La búsqueda solo se hace a petición del usuario y no confirma actualizaciones.

Si `var/bot.lock` está ocupado, detén el otro proceso de polling y reintenta sin borrar el archivo de bloqueo. Desde fuentes puedes comprobar el registro con `php bin/bot.php webhook info` y eliminarlo mediante `webhook delete` siguiendo el [procedimiento de cambio de modo](vds-webhook.md#cambio-de-modo). Si hay varios ID, no adivines: sigue la [verificación de identidad](telegram-bot.md#averigua-tu-id). Para buscar el ID inicialmente no necesitas perfil ni configuración de IA.

## Autorización, acceso y conflictos de recepción

| Error | Causa y solución |
| --- | --- |
| Bot API 401 / autorización rechazada | Comprueba el token BotFather y sustitúyelo en el INI tras renovarlo. |
| Bot API 403 / chat inaccesible | Comprueba que abriste conversación con el bot, no lo bloqueaste y pusiste tu propio ID. |
| Bot API 409 / conflicto polling | Detén la segunda copia y consulta `php bin/bot.php webhook info`. |
| «Занята блокировка var/bot.lock.» | Detén `run` u otro `identify` sin borrar el bloqueo. |
| «Недоступна блокировка var/bot.lock.» | Revisa permisos de `var/` privado y del propio bloqueo. |
| Modo webhook activo; inicio local prohibido | Utiliza `work-once` en el servidor o termina el cambio a polling. |
| Bot API 429 | La entrega final respeta un `retry_after` válido; no ejecutes múltiples receptores y espera al reintento permitido. |

El bot recibe actualizaciones mediante `getUpdates` **o** webhook. Cambiar el INI no elimina un webhook ya registrado. Para volver a polling, ejecuta `php bin/bot.php webhook delete` (conserva pendientes), comprueba «Webhook не зарегистрирован.» con `webhook info` y sigue el [procedimiento](vds-webhook.md#cambio-de-modo).

El modo `identify --json`, que usa el menú, devuelve deliberadamente un error genérico sin excepción interna. La categoría puede ser desconocida; eso no demuestra una causa concreta de red o configuración.

## Error de red o TLS

Para Telegram revisa DNS, acceso HTTPS a `api.telegram.org`, hora del sistema y certificados de confianza. El archivo standalone debe conservar `runtime/cacert.pem`. No desactives la comprobación TLS.

Para IA comprueba por separado servidor en marcha, modelo cargado, dirección accesible desde el entorno de la aplicación y puerto abierto en el cortafuegos. `127.0.0.1` dentro de WSL, VM o VDS no apunta a otro equipo; en modo `local` utiliza una IPv4 privada accesible.

El transporte de IA no utiliza proxies ni sigue redirecciones HTTP. Indica la dirección final del API. Las restricciones están en [`CurlAiTransport`](../../../src/Analysis/Http/CurlAiTransport.php).

## Oferta guardada sin evaluación

1. Asegúrate de que la supervisión local sigue ejecutándose. En servidor comprueba cron, usuario, rutas absolutas de PHP y proyecto y salida restringida.
2. Ten en cuenta cola y tiempos de respuesta: las peticiones son secuenciales y en el servidor se inician según cron.
3. Revisa el formato del modelo y del API. Escribe la URL base sin `/chat/completions`: la aplicación lo añade.
4. Para modelos lentos cambia `AI_REQUEST_TIMEOUT_SECONDS` de `180` a `300` o `600` y reinicia. Intervalo: 30–1800.
5. Si aparece «Не удалось обработать», corrige la causa antes de probar otra vez: la oferta sigue en la base, pero una tarea fallida definitivamente no se reanuda sola.

Recibir un webhook correctamente no significa que la IA esté lista: el controlador HTTP no carga el perfil ni llama al modelo. `work-once` debe completar su propia inicialización.

Para localizar el fallo abre **tu propia** `var/vacancies.sqlite` en un cliente SQLite local **solo lectura**, consultando estados sin textos de ofertas:

```sql
-- Solo lectura: últimos diez estados, sin texto de ofertas.
SELECT message_id, analysis_status, analysis_error, analysis_attempts,
       delivery_status, delivery_error, delivery_attempts
FROM analysis_jobs
ORDER BY message_id DESC
LIMIT 10;
```

Los estados están en [`SqliteAnalysisRepository`](../../../src/Storage/SqliteAnalysisRepository.php). No edites contadores ni estados manualmente. No publiques la base; elimina identificadores privados de los extractos que compartas.

## Modelo con error o salida inválida

| Categoría | Qué comprobar |
| --- | --- |
| `http_401`, `http_403` | Clave y permisos de acceso al modelo. |
| `http_404` | Ruta base del API e identificador del modelo según el proveedor. |
| `http_429`, `http_5xx` | Límites y disponibilidad; pueden permitir reintentos. |
| `timeout` | Tiempo máximo, carga y velocidad del modelo. |
| `invalid_response` | Estructura Chat Completions: `choices[0].message.content` como cadena, sin llamadas a herramientas. |
| `invalid_output` | Un único objeto JSON con los campos obligatorios, sin envoltorio o en **un** bloque Markdown etiquetado `json`, sin texto ajeno. |
| `response_size` | Respuesta API demasiado grande: 65 536 bytes máximo. |
| `vacancy_size_or_encoding` | UTF-8 de la oferta y límite de 65 536 bytes. |
| `transport` | Conexión, TLS y red del servidor de IA. |

`http_5xx` designa códigos concretos, como `http_503`. La posibilidad de reintentar depende de la causa y no solo de los intentos pendientes. Consulta [esquema y reglas de cadenas](analysis-result.md) y [configuración del modelo](ai-models.md).

## Evaluación en la BD pero no en el chat

Comprueba que el bot no esté bloqueado y el chat esté disponible. La entrega del resultado guardado tiene sus propios intentos y no vuelve a llamar al modelo. Con `delivery_status = failed`, el resultado sigue en SQLite, pero no se manda un aviso separado del error. No existe comando de reenvío manual.

El aviso de inicio del análisis tiene un solo intento, por lo que no recibirlo no significa que se perdió la tarea. Un fallo entre envío y confirmación puede duplicar el resultado. Consulta el [procesamiento](message-processing.md).

## SQLite o webhook inaccesibles

Comprueba espacio, permisos de lectura/escritura de la BD y acceso a `var/` para los diarios SQLite. En servidor CLI y FPM deben usar la misma base: verifica ambas cuentas. La espera de bloqueo SQLite está limitada a 5 segundos.

Webhook devuelve 503 si el almacenamiento falla/no está listo y 403 si el secreto es erróneo. Revisa `TELEGRAM_WEBHOOK_SECRET` en el INI privado y consulta `php bin/bot.php webhook info`. Si cambió el secreto, vuelve a registrar mediante `webhook set` y tu URL HTTPS. La [guía de servidor](vds-webhook.md) explica códigos HTTP, preparación de BD y permisos.

No borres `var/`, la base ni la configuración local para intentar un inicio «limpio». Conserva datos privados y estado de cola al actualizar.
