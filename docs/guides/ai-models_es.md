# Conexión de un modelo de IA

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](ai-models.md) | [English](ai-models_en.md) | **Seleccionado** | [中文](ai-models_zh.md) | [Français](ai-models_fr.md) | [Deutsch](ai-models_de.md) |

[← Volver al README](../readme/README_es.md)

La aplicación funciona con modelos locales y en la nube a través de la interfaz Chat Completions. Configura la dirección del servidor y el identificador del modelo en `config/bot.ini` según el [ejemplo](../../config/bot.example.ini).

## Requisitos de la API

El servidor debe aceptar una petición `POST` a `AI_BASE_URL` seguida de `/chat/completions`, mensajes con roles `system` y `user`, y `stream = false`. La respuesta debe contener `choices[0].message.content` como cadena con un solo objeto JSON conforme al esquema incluido por la aplicación en las instrucciones.

Se acepta un único objeto JSON en `message.content`, sin envoltorio o dentro de un solo bloque Markdown etiquetado `json`. No se admite texto adicional antes o después del objeto, bloques extra, otro formato Markdown ni respuestas fragmentadas. También se rechazan las llamadas a funciones y herramientas. El esquema se incluye en la propia solicitud; no se envía un parámetro separado `response_format`.

El formato requerido se describe en la [referencia del resultado](../reference/analysis-result_es.md). Implementación: [`OpenAiCompatibleEvaluator`](../../src/Analysis/OpenAiCompatibleEvaluator.php) y [`EvaluationPromptBuilder`](../../src/Analysis/EvaluationPromptBuilder.php).

## Modelo local

Inicia el servidor API con el modelo cargado y configura:

```ini
; Modelo local: sustituye model-id y, si es necesario, la dirección API.
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

La dirección y `model-id` son ejemplos que debes sustituir. Obtén el identificador del servidor; el nombre del archivo del modelo puede diferir del utilizado por la API. Si el servidor exige una clave, rellena `AI_API_KEY`.

Se permiten HTTP y HTTPS. En modo `local`, la validación acepta:

| Dirección | Cuándo utilizarla |
| --- | --- |
| `localhost`, IPv4 `127.0.0.0/8`, IPv6 `[::1]` | Servidor del modelo en el mismo equipo. |
| `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` | Servidor accesible mediante IPv4 privada de la red. |

Esta validación no permite otros nombres de host, IP públicas ni IPv6 privadas. Para un modelo en otro equipo utiliza su IPv4 privada. En WSL, una máquina virtual o una VDS, `127.0.0.1` se refiere al entorno donde se ejecuta PHP, no a tu ordenador de escritorio.

Restringe el acceso a la API a los equipos necesarios. La aplicación no instala modelos ni abre puertos. Los requisitos de memoria, hardware y ventana de contexto dependen del modelo elegido. El contexto debe admitir el perfil, las instrucciones, el esquema y la oferta completa.

## Servicio en la nube

Crea una clave con tu proveedor y obtén la URL base y el identificador del modelo de su documentación. Ejemplo con dirección ficticia:

```ini
; API en la nube: sustituye dirección y modelo, y especifica una clave.
AI_PROVIDER_LOCATION = "cloud"
AI_BASE_URL = "https://api.example.org/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

Sustituye la dirección y el modelo y escribe una clave no vacía con un editor. `cloud` exige HTTPS y una clave. La URL no puede contener usuario, contraseña, parámetros de consulta ni fragmento. No añadas `/chat/completions` a `AI_BASE_URL`.

El servicio en la nube recibe el texto de la oferta, el perfil y las instrucciones para el análisis. La solicitud se describe en [`EvaluationPromptBuilder::build()`](../../src/Analysis/EvaluationPromptBuilder.php).

## Perfil y reglas de evaluación

Crea `config/profile/local/candidate.md` con tu experiencia y preferencias y `config/profile/local/instructions.md` con las reglas de evaluación. Empieza por el [ejemplo de instrucciones](../../config/profile/instructions.example.md) y la [guía del perfil](../../config/profile/README_es.md).

Define los requisitos obligatorios, cómo interpretar datos ausentes y el idioma de la explicación. Exige exactamente el JSON del esquema de la aplicación, sin enlaces, formato ni campos adicionales. Las instrucciones del propio anuncio no deben sustituir tus reglas. Ejemplos: [desarrollador Go](../examples/go-developer_es.md) y [analista de producto](../examples/analyst_es.md).

Reinicia el seguimiento local tras editar los archivos; en el servidor, la próxima ejecución de `work-once` cargará los cambios. Las evaluaciones guardadas no se recalculan.

## Tiempos de espera y reintentos

```ini
; Número de intentos (incluido el primero) y segundos por petición IA.
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

`AI_REQUEST_TIMEOUT_SECONDS` es un entero de 30 a 1800 segundos, predeterminado 180 si falta la clave. Limita cada petición por separado. La conexión tiene un límite de 5 segundos. Para un modelo local lento puedes usar `300` o `600` y reiniciar el procesamiento.

`AI_MAX_ATTEMPTS` acepta 1–5, con valor predeterminado 3, incluido el intento inicial. El límite se aplica de forma independiente al análisis y a la entrega del resultado. Para una prueba puntual de un modelo lento, `1` reduce la espera tras un fallo.

Los fallos temporales de red, los tiempos de espera agotados y las respuestas HTTP 429 y 5xx pueden provocar reintentos. Una respuesta del modelo con formato inválido también puede reintentarse. Los errores de autorización, como HTTP 401, interrumpen los intentos restantes. Las esperas entre intentos son 30, 60, 120 y 240 segundos. Tras reiniciar se tienen en cuenta los intentos ya utilizados. [`VacancyAnalysisPipeline`](../../src/Application/VacancyAnalysisPipeline.php) decide los reintentos y [`CurlAiTransport`](../../src/Analysis/Http/CurlAiTransport.php) aplica los límites de red.

Las peticiones de IA no siguen redirecciones HTTP ni usan proxies; TLS se verifica. El texto de la oferta tiene un límite de 65 536 bytes, la respuesta API de 65 536 bytes y la solicitud construida de 262 144 bytes. Las respuestas excesivas o inválidas se rechazan.

## Exclusiones exactas opcionales

El prefiltro permite omitir mensajes cortos definidos exactamente sin enviarlos al modelo.

### Una regla

```ini
; Coincidencia del mensaje completo; un término ambiguo anula el rechazo.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
```

### Varias reglas y excepciones

Cada regla ocupa una línea independiente:

```ini
; Cada frase exacta y cada término de ambigüedad va por separado.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

En este ejemplo, `Работа только в офисе.` se rechaza solo como mensaje completo independiente; `Работа только в офисе. Возможны исключения` se pasa al modelo. Si hay una coincidencia exacta pero también aparece un término ambiguo, el rechazo se anula.

Cada regla utiliza una línea con `[]`. Solo se puede rechazar si coincide **el mensaje entero**, no una frase incluida en una oferta larga. Se distingue entre mayúsculas y minúsculas y se ignoran espacios exteriores. La [referencia de configuración](../reference/configuration_es.md#prefiltro) muestra varias reglas. Una subcadena de la oferta no provoca rechazo. Cualquier término de ambigüedad anula el rechazo sin distinguir mayúsculas y minúsculas. Los mensajes con saltos de línea se envían al modelo. Las reglas son cadenas UTF-8 literales, no expresiones regulares. El comportamiento está en [`ConservativePrefilter`](../../src/Analysis/ConservativePrefilter.php).

## Comprueba la conexión

Primero ejecuta la comprobación de preparación local y después envía al bot un texto ficticio breve con el seguimiento o cron en marcha. La comprobación de preparación no contacta al servidor del modelo ni comprueba la clave o que el modelo admita el JSON requerido.

Evalúa la calidad del contenido: ¿distingue requisitos obligatorios de preferencias, señala datos desconocidos y evita inventar experiencia? Ante un fallo, consulta la [solución de problemas](troubleshooting_es.md). Si la evaluación no es satisfactoria, revisa el perfil y las instrucciones.
