# Aplicación local

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/local-gum.md) | [English](../en/local-gum.md) | **Seleccionado** | [中文](../zh/local-gum.md) | [Français](../fr/local-gum.md) | [Deutsch](../de/local-gum.md) |

[← Volver al README](README.md)

Envía el texto de una oferta al bot o reenvía una publicación desde otro chat o canal. La aplicación guarda la oferta, la evalúa según tu perfil y te envía el resultado. Mantén abierta la ventana de la aplicación mientras se ejecuta la supervisión.

## Elige un archivo

Los archivos standalone para Linux x86_64, Windows x64 y macOS ARM64 están disponibles en [Releases](https://github.com/yaleksandr89/telegram-vacancy-monitor/releases). Elige el correspondiente a tu plataforma en la tabla siguiente.

| Plataforma | Nombre del archivo | Requisitos del equipo |
| --- | --- | --- |
| Linux x86_64 | `telegram-vacancy-monitor-linux-x86_64.tar.gz` | Terminal interactivo, Bash y `dirname` |
| Windows x64 | `telegram-vacancy-monitor-windows-x64.zip` | Windows 10/11 y Windows PowerShell 5.1 incluido en el sistema |
| macOS ARM64 | `telegram-vacancy-monitor-macos-arm64.tar.gz` | macOS 14+ con Apple Silicon y terminal interactivo |

El archivo incluye una carpeta `telegram-vacancy-monitor/` con ejecutable, PHP, Gum, ejemplos de configuración, `LICENSE` y dos guías: `docs/langs/ru/local-gum.md` y `docs/langs/ru/vds-webhook.md`. El resto de la documentación está en el repositorio. En Windows PowerShell inicia el menú de Gum. No necesitas instalar PHP, Composer ni Gum por separado; el servidor del modelo se inicia aparte.

Conserva toda la estructura de carpetas. Linux utiliza las bibliotecas y el cargador incluidos; macOS también usa bibliotecas del sistema. ARM64 está destinado a Apple Silicon y Windows x64 a equipos Windows x64. Si macOS bloquea la aplicación descargada, comprueba su origen y el aviso de seguridad sin desactivar toda la protección del sistema.

El [empaquetador](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/build/common/package.py) determina el contenido. Antes de iniciar, el ejecutable comprueba la integridad de archivos mediante `SHA256SUMS.json`. Guarda la configuración en los archivos indicados abajo.

## Extracción e inicio

Extrae el archivo a una carpeta personal con permisos de escritura. Ejecuta los comandos desde la carpeta donde descargaste el archivo.

Linux:

```bash
# Extrae el archivo e inicia la aplicación desde la carpeta creada.
tar -xzf telegram-vacancy-monitor-linux-x86_64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Windows: extrae el ZIP y abre `telegram-vacancy-monitor/vacancy-monitor.exe` con **doble clic**. También puedes iniciarlo en PowerShell:

```powershell
# Extrae en una carpeta aparte e inicia la aplicación.
Expand-Archive -LiteralPath .\telegram-vacancy-monitor-windows-x64.zip -DestinationPath .\vacancy-monitor-app
Set-Location -LiteralPath .\vacancy-monitor-app\telegram-vacancy-monitor
.\vacancy-monitor.exe
```

macOS con Apple Silicon:

```bash
# Extrae la compilación ARM64 y ejecútala.
tar -xzf telegram-vacancy-monitor-macos-arm64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Para volver a iniciar en Windows, haz doble clic en `vacancy-monitor.exe`. En Linux y macOS ejecuta `./vacancy-monitor` desde la carpeta de la aplicación. Se admiten rutas con espacios.

## Vista de la aplicación

El menú principal, la ayuda y los mensajes de configuración se muestran en la [guía visual de la interfaz](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/local-interface.md). El producto ofrece interfaz en ruso e inglés, no en todos los idiomas de la documentación.

## Crea la configuración privada

Todas las rutas siguientes se interpretan desde la carpeta del ejecutable. Copia el [ejemplo](../../../config/bot.example.ini) a `config/bot.ini` solo si el archivo todavía no existe.

Linux/macOS:

```bash
# Crea la configuración sin sobrescribir un archivo existente.
cp -n config/bot.example.ini config/bot.ini

# Prepara la carpeta del perfil y copia las instrucciones de ejemplo.
mkdir -p config/profile/local
cp -n config/profile/instructions.example.md config/profile/local/instructions.md
```

Windows PowerShell:

```powershell
# No sobrescribas tu configuración durante la preparación.
if (-not (Test-Path -LiteralPath .\config\bot.ini)) {
    Copy-Item -LiteralPath .\config\bot.example.ini -Destination .\config\bot.ini
}
# Prepara la carpeta de los archivos privados del perfil.
New-Item -ItemType Directory -Path .\config\profile\local -Force | Out-Null
# Copia las instrucciones de ejemplo solo si aún no existen.
if (-not (Test-Path -LiteralPath .\config\profile\local\instructions.md)) {
    Copy-Item -LiteralPath .\config\profile\instructions.example.md -Destination .\config\profile\local\instructions.md
}
```

Edita `config/bot.ini` con un editor; nunca escribas secretos como comandos en la terminal.

1. Abre [BotFather](https://t.me/BotFather), crea un bot con `/newbot` y guarda el token en `TELEGRAM_BOT_TOKEN`.
2. Envía `/start` al bot en un mensaje independiente, sin reenviar y sin texto adicional.
3. En la aplicación selecciona «Узнать Telegram User ID» («Obtener ID de Telegram») y «Проверить сейчас» («Comprobar ahora»). En este paso basta con el token y el modo `polling`: el perfil y la IA aún no son necesarios.
4. Guarda **tu ID numérico** en `TELEGRAM_ALLOWED_USER_ID`. Si hay varios ID y no puedes reconocer el tuyo, no adivines: sigue el [procedimiento de la guía del bot](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/telegram-bot.md#averigua-tu-id).
5. Mantén `TELEGRAM_RECEIVE_MODE = "polling"`.

La búsqueda del ID se realiza solo cuando la solicitas y no confirma actualizaciones pendientes. `identify` comparte el bloqueo no bloqueante `var/bot.lock`: detén antes la supervisión sin borrar el archivo de bloqueo. Si hay un webhook registrado, sigue primero el [cambio seguro a modo local](vds-webhook.md#cambio-de-modo). Más detalles en la [guía en línea del bot](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/telegram-bot.md).

## Prepara el perfil y el modelo

Crea `config/profile/local/candidate.md` en un editor con tu experiencia, capacidades, trabajo deseado y restricciones. Define las reglas de evaluación en `config/profile/local/instructions.md`. Ambos archivos son obligatorios, no vacíos, UTF-8 válido y de hasta 32 KiB y 16 000 caracteres cada uno. Consulta la [guía del perfil](profile.md).

Inicia un servidor de modelo compatible con Chat Completions. Si funciona en el mismo equipo, puedes usar este ejemplo:

```ini
; Ejemplo de API local: indica el identificador de tu modelo.
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

Sustituye `model-id` por el identificador API del modelo cargado y ajusta la dirección a tu servidor. La aplicación añade `/chat/completions` a `AI_BASE_URL`. El servidor debe aceptar solicitudes sin streaming y devolver JSON en `message.content` conforme al esquema recibido. Comprueba la compatibilidad con una oferta de prueba.

En modo `local`, se permiten `localhost`, `127.0.0.0/8`, `[::1]` y direcciones IPv4 privadas de `10.0.0.0/8`, `172.16.0.0/12` y `192.168.0.0/16`. Para otro equipo usa su IPv4 privada y limita el acceso a la red necesaria. Un nombre de host de la red local no supera la validación. `127.0.0.1` señala el equipo donde se ejecuta la aplicación.

Para un modelo en la nube utiliza `AI_PROVIDER_LOCATION = "cloud"`, URL HTTPS, identificador del modelo y `AI_API_KEY`.

Más información: [conectar modelos](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/ai-models.md) y [referencia INI](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/configuration.md).

## Comprueba la preparación y comienza

Selecciona «Проверить готовность» («Comprobar preparación»). «Подробнее» («Detalles») muestra el estado del entorno, configuración, perfil y almacenamiento. Después puedes enviar una [oferta de ejemplo](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/vacancy-analysis.md) para probar el proceso completo: esa página no está incluida en los archivos standalone.

Una vez configurado, elige «Запустить мониторинг» («Iniciar supervisión») y envía al bot una oferta breve. Normalmente recibirás `Вакансия сохранена.`, un aviso de comienzo y la evaluación. El resultado contiene veredicto, prioridad, argumentos, condiciones, coincidencias, riesgos y preguntas. La fuente figura por separado; un enlace de la oferta no queda verificado automáticamente.

Las peticiones al modelo se procesan secuencialmente. Para modelos lentos aumenta `AI_REQUEST_TIMEOUT_SECONDS` a `300` o `600` y reinicia la supervisión. El rango es de 30 a 1800 segundos por petición. `AI_MAX_ATTEMPTS` admite entre 1 y 5 intentos, por separado para análisis y entrega, incluido el inicial. Un error permanente puede terminar el proceso inmediatamente.

La evaluación se guarda antes del envío, por lo que entregar el mismo mensaje otra vez no exige repetir el análisis. Consulta [duplicados y recuperación](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/message-processing.md).

## Menú y parada

| Acción | Función |
| --- | --- |
| «Запустить мониторинг» | Recibir mensajes y procesar la cola mientras el proceso siga abierto. |
| «Проверить готовность» | Diagnóstico local sin llamar a ninguna API. |
| «Узнать Telegram User ID» | Búsqueda manual de remitentes de `/start`. |
| «Справка» | Inicio rápido, procesamiento, privacidad, modelo y servidor. |
| «Язык» | Cambiar el menú entre ruso e inglés hasta cerrar la aplicación. |
| «Выход» | Salir de la aplicación. |

Navega con flechas y Enter. Esc vuelve atrás y, en el menú principal, lo deja abierto. Ctrl+C detiene la supervisión y la aplicación. Al terminar una acción o fallar, el menú ofrece reintentar, volver o salir; no hay reinicio automático. El idioma de respuesta del modelo se define en `instructions.md`, no en el menú.

## Datos y actualizaciones

Los archivos personales se crean durante la configuración y no vienen en el archivo descargado. Ejemplos: [`config/bot.example.ini`](../../../config/bot.example.ini), [perfil de Go](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/go-developer.md#perfil-del-candidato), [perfil de analista](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/analyst.md#perfil-del-candidato) e [instrucciones del modelo](../../../config/profile/instructions.example.md).

| Ruta | Qué conservar |
| --- | --- |
| `config/bot.ini` | Token, ID de Telegram, conexión de IA y reglas. |
| `config/profile/local/` | Perfil, instrucciones y copias privadas. |
| `var/` | SQLite, cola y estado del procesamiento. |

Antes de actualizar, detén la aplicación y confirma que el proceso acabó. Haz una copia privada de esas tres rutas. Extrae el archivo nuevo en otra carpeta, traslada los archivos privados y todo `var/` a los lugares correspondientes, compara tu INI con el ejemplo nuevo e inicia únicamente la copia nueva. No extraigas sobre una instalación en uso ni sustituyas tu base de datos por una vacía.

Reinicia la supervisión tras cambiar la configuración, el perfil o las instrucciones. Las evaluaciones guardadas no se recalculan automáticamente.

## Si algo falla

- **No aparece el ID:** envía `/start` y elige «Проверить сейчас» de nuevo. Revisa token, webhook y `var/bot.lock`.
- **Error de configuración:** revisa `config/bot.ini`, ambos archivos del perfil y los detalles de preparación.
- **Error de autorización:** comprueba el token de BotFather; después de cambiarlo, guarda el INI y reinicia.
- **Error de red o TLS:** comprueba Telegram, DNS, fecha del sistema y certificados. No desactives TLS.
- **Almacenamiento inaccesible:** comprueba espacio libre y permisos de la carpeta de aplicación, `var/` y SQLite. No borres la base de datos ni el bloqueo para evitar un error.
- **Oferta guardada sin evaluación:** confirma que la supervisión funciona, el modelo está disponible y devuelve JSON válido.
- **Archivos públicos dañados:** extrae de nuevo un archivo fiable en otra carpeta y restaura solo datos privados.

Más ayuda: [solución de problemas](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/troubleshooting.md) y [formato del resultado](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/analysis-result.md).
