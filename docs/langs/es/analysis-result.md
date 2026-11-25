# Formato de evaluación y notificaciones

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/analysis-result.md) | [English](../en/analysis-result.md) | **Seleccionado** | [中文](../zh/analysis-result.md) | [Français](../fr/analysis-result.md) | [Deutsch](../de/analysis-result.md) |

[← Volver al README](README.md)

La aplicación recibe una evaluación estructurada del modelo, la guarda y genera una notificación de texto independiente para Telegram. El JSON del modelo y el mensaje al usuario tienen formatos distintos.

## Respuesta API y JSON del modelo

[`OpenAiCompatibleEvaluator`](../../../src/Analysis/OpenAiCompatibleEvaluator.php) acepta una respuesta Chat Completions sin streaming: el primer elemento de `choices` debe contener `message.content` de tipo cadena. En esa cadena debe haber exactamente un objeto JSON. Se rechazan `tool_calls` no vacíos y cualquier `function_call` no nulo; no se ejecutan herramientas.

[`EvaluationPromptBuilder::build()`](../../../src/Analysis/EvaluationPromptBuilder.php) incluye el esquema en el mensaje `system` junto con tus instrucciones. El perfil y el texto de la oferta, que no es confiable, se envían en un mensaje `user`, sin transmitir por separado metadatos del chat.

Todos los campos siguientes son obligatorios y no se admiten otros. [`AnalysisResult::jsonSchema()`](../../../src/Analysis/AnalysisResult.php) define la estructura y [`AnalysisResultValidator::validate()`](../../../src/Analysis/AnalysisResultValidator.php) realiza la validación.

| Campo | Tipo y valores permitidos | Significado |
| --- | --- | --- |
| `company` | Cadena no vacía | Empresa o indicación de que no se especificó. |
| `position` | Cadena no vacía | Puesto. |
| `verdict` | `apply`, `borderline`, `skip` | Decisión sobre si merece la pena postularse. |
| `priority` | `high`, `medium`, `low` | Prioridad de la oferta. |
| `matches` | Array de cadenas no vacías; puede estar vacío | Requisitos que coinciden con el perfil. |
| `risks` | Array de cadenas no vacías; puede estar vacío | Riesgos y discrepancias importantes. |
| `geography` | Cadena no vacía | Ubicación y modalidad de trabajo. |
| `pay` | Cadena no vacía | Remuneración o indicación de su ausencia. |
| `missing` | Array de cadenas no vacías; puede estar vacío | Preguntas e información pendiente. |
| `rationale` | Cadena no vacía | Justificación breve. |
| `source_status` | Solo `unavailable` en la respuesta del modelo | El modelo no verifica el origen de la publicación. |

Las cadenas deben ser UTF-8 válido y contener texto visible. El validador rechaza caracteres de control, formato invisible, separadores de línea, enlaces HTTP/HTTPS y construcciones semejantes a etiquetas HTML. Tampoco se permiten saltos de línea dentro de las cadenas; utiliza elementos separados de los arrays. El único envoltorio externo permitido es un bloque Markdown etiquetado exactamente `json`, que el adaptador retira antes de validar estrictamente el objeto. Se rechazan textos añadidos, otros bloques y formato adicional.

A continuación se muestra una **plantilla sintética de objeto dentro de `message.content`** con valores de ejemplo en ruso, según el escenario de demostración:

```json
{
  "company": "Компания из объявления",
  "position": "Должность из объявления",
  "verdict": "borderline",
  "priority": "medium",
  "matches": ["Подтверждённое совпадение"],
  "risks": [],
  "geography": "Формат и география из объявления",
  "pay": "Не указана",
  "missing": ["Условие, которое нужно уточнить"],
  "rationale": "Краткое объяснение на основе профиля и вакансии",
  "source_status": "unavailable"
}
```

En una respuesta HTTP, este objeto debe estar codificado como **cadena** dentro de `choices[0].message.content`, no anidado como objeto. La respuesta API completa está limitada a 65 536 bytes. Los errores del envoltorio de respuesta o de JSON pueden reintentarse dentro de `AI_MAX_ATTEMPTS`.

## Veredicto y prioridad

Los valores los definen [`Verdict`](../../../src/Analysis/Verdict.php) y [`Priority`](../../../src/Analysis/Priority.php); las etiquetas en ruso las genera [`VacancyResultFormatter::format()`](../../../src/Application/VacancyResultFormatter.php).

| Valor | Texto en Telegram (ruso) | Interpretación |
| --- | --- | --- |
| `apply` | Откликаться | Conviene considerar postularse. |
| `borderline` | Погранично | Existen dudas o preguntas importantes por aclarar. |
| `skip` | Пропустить | No conviene considerar la oferta según los criterios establecidos. |
| `high` | высокий | Prioridad alta. |
| `medium` | средний | Prioridad media. |
| `low` | низкий | Prioridad baja. |

La aplicación valida los valores permitidos, pero no impone un umbral numérico ni exige combinaciones determinadas de veredicto y prioridad. Los criterios de fondo se definen en `instructions.md`; **la decisión la toma el modelo**. El veredicto no envía ninguna candidatura al empleador.

## Notificación de Telegram

El [formateador](../../../src/Application/VacancyResultFormatter.php) construye un mensaje de texto normal. Abajo aparece una plantilla de distribución con marcadores, **no un resultado real**. Las etiquetas son rusas porque los mensajes de la aplicación están en ruso:

```text
Откликаться / Погранично / Пропустить
Приоритет: высокий / средний / низкий
{компания} — {должность}
{краткое обоснование}
География и формат: {география и формат}
Оплата: {оплата}
Совпадения: {пункты через точку с запятой}
Риски: {пункты через точку с запятой}
Уточнить: {пункты через точку с запятой}
Источник: {ссылка с пометкой или «ссылка недоступна»}
Сообщение в чате: {ID входящего сообщения}
```

Se omiten las listas vacías `matches`, `risks` y `missing`. El ID de la última línea corresponde al mensaje recibido en el chat del bot, no a la publicación original del canal.

Si el mensaje completo supera 4096 unidades UTF-16, se acorta con la marca `(сокращено для Telegram)`. La empresa, el puesto y la justificación reciben sus propias etiquetas; se conservan el veredicto, la prioridad y la fuente. Los emojis se manejan sin cortar caracteres. La evaluación completa permanece en SQLite.

## Fuente y fiabilidad

Los enlaces se obtienen del texto de la oferta, no de la respuesta del modelo. La aplicación no abre sitios web ni descarga páginas.

- Si no hay un enlace válido, muestra `Источник: ссылка недоступна` («Fuente: enlace no disponible»).
- Si encuentra un enlace HTTP/HTTPS, muestra `ссылка из объявления (не проверена)` («enlace del anuncio, no verificado»).
- Si ya existe un enlace `https://t.me/c/…/…` cuyos ID numéricos coinciden con los datos de una publicación reenviada desde un canal, muestra `совпадает с ID пересланной публикации` («coincide con el ID de la publicación reenviada»).

La selección y validación exactas están en [`sourceDescription()` y `verifiedSource()`](../../../src/Application/VacancyResultFormatter.php). La coincidencia de ID confirma la relación del enlace con el reenvío, **no** la veracidad de la oferta ni la fiabilidad del empleador. No se construye una URL solo a partir de metadatos.

En la respuesta del modelo, `source_status` siempre debe ser `unavailable`. Al guardar [`AnalysisResult::toJson()`](../../../src/Analysis/AnalysisResult.php), la aplicación establece por sí misma `verified_origin_ids` si se confirma la coincidencia entre enlace e ID; de lo contrario conserva `unavailable`. El modelo no puede atribuirse un estado verificado.

## Mensajes del procesamiento

[`IncomingUpdateProcessor`](../../../src/Application/IncomingUpdateProcessor.php) y [`VacancyAnalysisPipeline`](../../../src/Application/VacancyAnalysisPipeline.php) separan la recepción del procesamiento.

| Evento | Mensaje o estado |
| --- | --- |
| Oferta nueva guardada | `Вакансия сохранена.` |
| Publicación conocida enviada otra vez | `Эта публикация уже сохранена.` |
| Inicio de solicitud de IA | `Вакансия передана на анализ ИИ. Результат придёт сюда, как только анализ завершится.` |
| Rechazo exacto del prefiltro | `Пропустить` y razón de coincidencia exacta, sin evaluación de IA ni aviso de inicio. |
| Error definitivo de análisis | `Не удалось обработать` y `Техническая ошибка анализа. Исходная вакансия сохранена.`, seguidos de fuente e ID del mensaje. |
| Error definitivo de entrega | `delivery_status = failed` en SQLite; no se envía un aviso aparte del error de entrega. |

## Etapas de procesamiento

1. **Guardado.** La aplicación almacena oferta y tarea en SQLite. Tras guardar correctamente, el bot envía `Вакансия сохранена.`. La repetición de la misma actualización no crea otra oferta.
2. **Comienzo del análisis.** El bot intenta enviar el aviso de inicio una sola vez. Aunque no llegue, el análisis continúa.
3. **Evaluación.** El modelo compara la oferta con el perfil y las instrucciones. La evaluación terminada se almacena en SQLite **antes** de enviarla a Telegram.
4. **Entrega.** El bot envía el resultado guardado. Ante un error temporal de entrega, puede reintentar **sin volver a llamar al modelo**.
5. **Recuperación.** Tras reiniciar, las tareas pendientes continúan teniendo en cuenta los intentos ya consumidos. Las tareas agotadas no se reinician automáticamente.

Durante un fallo de red en el envío, Telegram puede recibir el mensaje aunque la aplicación nunca reciba la confirmación, por lo que el resultado podría enviarse dos veces. Los reintentos, el cursor y el almacenamiento se explican en la [guía de procesamiento](message-processing.md). La implementación está en [`VacancyAnalysisPipeline`](../../../src/Application/VacancyAnalysisPipeline.php).
