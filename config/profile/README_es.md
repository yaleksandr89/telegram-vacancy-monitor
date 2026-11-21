# Perfil del candidato e instrucciones

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](README.md) | [English](README_en.md) | **Seleccionado** | [中文](README_zh.md) | [Français](README_fr.md) | [Deutsch](README_de.md) |

[← Volver al README](../../docs/readme/README_es.md)

La evaluación personalizada requiere dos archivos Markdown privados. Se cargan al comenzar el procesamiento desde el directorio fijo `config/profile/local/`, relativo a la raíz de la aplicación.

| Archivo | Contenido |
| --- | --- |
| `config/profile/local/candidate.md` | Experiencia, habilidades, responsabilidades deseadas, modalidad de trabajo y limitaciones del candidato |
| `config/profile/local/instructions.md` | Reglas de comparación de ofertas, elección del veredicto, prioridad e idioma de las explicaciones |

Crea el directorio `local/`, redacta `candidate.md` con un editor de texto y copia el [ejemplo de instrucciones](instructions.example.md) en `local/instructions.md`. Basta con Markdown corriente; no hay títulos ni campos obligatorios. No modifiques el ejemplo público en lugar de tu copia privada.

## Qué escribir

Describe habilidades y experiencia, tareas deseadas, modalidad de trabajo, ubicación, expectativas salariales y restricciones importantes. Puedes orientarte con el ejemplo del [desarrollador Go](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_es.md#perfil-del-candidato) o del [analista](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_es.md#perfil-del-candidato).

En las instrucciones define qué requisitos son obligatorios, cuándo asignar prioridad alta y qué condiciones deben aclararse. El [ejemplo de instrucciones](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_es.md#instrucciones-de-evaluación) muestra cómo organizar las reglas. Aquí también se establece el idioma de las respuestas del modelo. Las instrucciones distribuidas piden explicaciones en ruso; traducir esta guía no modifica ese comportamiento.

La aplicación añade el esquema JSON a las instrucciones y transmite por separado el perfil y el texto de la oferta. Conserva la exigencia de devolver un solo objeto JSON sin campos adicionales, enlaces ni formato. El anuncio es material de análisis, no una fuente de órdenes para el modelo. [`EvaluationPromptBuilder`](../../src/Analysis/EvaluationPromptBuilder.php) describe la construcción de la solicitud y la [referencia en línea](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result_es.md), los campos de respuesta.

Ejemplos listos para copiar: [perfil e instrucciones de un desarrollador Go](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_es.md) y de un [analista de producto](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_es.md).

## Límites de los archivos

- Ambos archivos son obligatorios, no pueden estar vacíos y deben ser legibles; no se permiten enlaces simbólicos.
- La codificación debe ser UTF-8 válido.
- Cada archivo admite como máximo 32 768 bytes (32 KiB) del texto original y 16 000 caracteres, incluidos espacios y saltos de línea.
- Se permiten tabulaciones y saltos de línea normales; los demás caracteres de control están prohibidos.

Las comprobaciones las realizan [`CandidateProfileLoader`](../../src/Analysis/CandidateProfileLoader.php), [`CandidateProfile`](../../src/Analysis/CandidateProfile.php), [`EvaluationInstructionsLoader`](../../src/Analysis/EvaluationInstructionsLoader.php) y [`EvaluationInstructions`](../../src/Analysis/EvaluationInstructions.php). Si falta un archivo o no es válido, la CLI se detiene antes de recibir y procesar mensajes. El comando `identify` no necesita estos archivos. En modo servidor, el webhook puede guardar mensajes aunque el perfil no esté preparado, pero `work-once` no puede procesar la cola sin él.

## Recargar los cambios

Después de editar los archivos, detén y vuelve a iniciar el seguimiento local. En el servidor, la siguiente ejecución de `work-once` cargará los archivos nuevos; un proceso ya activo sigue usando los datos que tenía cargados. Las evaluaciones guardadas no se recalculan automáticamente.

El directorio `config/profile/local/` está excluido de Git. Durante una actualización, conserva los archivos del perfil junto con `config/bot.ini` y los datos de `var/`. La conexión con modelos se explica en la [guía](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models_es.md).

La dirección y clave del modelo, el tiempo de espera y el número de intentos se definen en `config/bot.ini` según el [ejemplo de configuración](../bot.example.ini). El inicio se describe en las guías [local](../../docs/guides/local-gum_es.md) y [servidor](../../docs/guides/vds-webhook_es.md).
