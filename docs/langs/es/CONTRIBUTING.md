# Contribuciones

## Elige un idioma

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/.github/CONTRIBUTING.md) | [English](../en/CONTRIBUTING.md) | **Seleccionado** | [中文](../zh/CONTRIBUTING.md) | [Français](../fr/CONTRIBUTING.md) | [Deutsch](../de/CONTRIBUTING.md) |

[← Volver al README](README.md)

Telegram Vacancy Monitor acepta sugerencias, correcciones de errores y mejoras. Gracias por tu interés en el proyecto.

## Errores y sugerencias

Utiliza [GitHub Issues](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) para informar de errores y debatir nuevas funciones. Revisa los issues existentes antes de abrir uno. Conviene discutir los cambios importantes antes de empezar a implementarlos.

Comunica las posibles vulnerabilidades siguiendo la [política de seguridad](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/policy).

## Desarrollo

Para trabajar con el código fuente necesitas PHP 8.5, Composer y las extensiones PHP `ext-curl`, `ext-json`, `ext-mbstring`, `ext-pdo` y `ext-pdo_sqlite`.

Instala las dependencias con `composer install`. Comprueba el proyecto con `composer check`. Los cambios en las interfaces y paquetes de cada plataforma deben verificarse también en los sistemas operativos correspondientes.

`composer check` incluye la validación estricta de Composer, la auditoría del archivo lock, PHP lint, PHP CS Fixer (PER Coding Style 3.1), PHPStan level 8 y pruebas smoke. Las herramientas de desarrollo requieren PHP 8.5; la compatibilidad de la aplicación con PHP 8.6 se comprueba por separado sin dependencias de desarrollo. Las distribuciones standalone no incluyen paquetes de desarrollo instalados.

En Windows, las pruebas de la interfaz standalone utilizan PHP 8.5.13 o una versión de mantenimiento posterior de la rama 8.5. Si el PHP del sistema está fuera de ese intervalo, antes de ejecutar `composer test`, asigna a la variable de entorno de pruebas `TVM_TEST_BUNDLE_PHP` la ruta absoluta de un `php.exe` compatible y verificado. El PHP del sistema sigue utilizándose para comprobar la compatibilidad de la aplicación; esta variable no cambia la configuración ni las restricciones del paquete.

## Pull Request

Describe el objetivo y resultado del cambio, así como las comprobaciones realizadas. Si cambia el comportamiento, añade las pruebas correspondientes y actualiza la documentación afectada.

No incluyas tokens, claves de API, configuración privada ni datos personales en pull requests, issues o registros.

## Licencia

El código del proyecto se distribuye bajo la [licencia MIT](../../../LICENSE).
