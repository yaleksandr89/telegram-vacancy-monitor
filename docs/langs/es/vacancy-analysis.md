# Ejemplos de evaluación de ofertas

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/vacancy-analysis.md) | [English](../en/vacancy-analysis.md) | **Seleccionado** | [中文](../zh/vacancy-analysis.md) | [Français](../fr/vacancy-analysis.md) | [Deutsch](../de/vacancy-analysis.md) |

[← Volver al README](README.md)

Dos ejemplos independientes muestran cómo preparar el perfil del candidato, las instrucciones de evaluación y el texto de una oferta. Todos los datos de entrada son ficticios; el resultado depende del modelo elegido y de las reglas configuradas.

- [Desarrollador Go](go-developer.md) — desarrollo de servicios, requisitos de experiencia, guardias y remuneración.
- [Analista de producto](analyst.md) — SQL, investigación, experimentos y condiciones laborales.

## Cómo usar los ejemplos

1. Guarda el perfil elegido en `config/profile/local/candidate.md` y las instrucciones en `config/profile/local/instructions.md`.
2. [Conecta un modelo](ai-models.md) e inicia el seguimiento.
3. Envía al bot el texto de la oferta del ejemplo correspondiente.
4. Lee la explicación y las preguntas recibidas. La evaluación no está predeterminada.

Los detalles de la estructura del resultado están en la [referencia](analysis-result.md).
