<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application;

use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ConfigurationException;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\StartupException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotApiException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;

final readonly class WebhookCommand
{
    private const string USAGE = 'Использование: php bin/bot.php webhook set <https-url> | info | delete';

    private function __construct(private string $action, private string $url = '') {}

    /** @param list<string> $arguments */
    public static function fromArguments(array $arguments): self
    {
        if ($arguments === ['info'] || $arguments === ['delete']) {
            return new self($arguments[0]);
        }
        if (count($arguments) !== 2 || $arguments[0] !== 'set') {
            throw new ConfigurationException(self::USAGE);
        }
        if (!self::isValidUrl($arguments[1])) {
            throw new ConfigurationException(
                'Укажите HTTPS-адрес с публичным DNS-именем и портом 443, 80, 88 или 8443, без учётных данных, запроса и фрагмента.'
                . PHP_EOL . self::USAGE,
            );
        }

        return new self('set', $arguments[1]);
    }

    /** @param array<string, mixed> $config */
    public function execute(BotApiClient $bot, #[\SensitiveParameter] array $config): string
    {
        try {
            if ($this->action === 'set') {
                if (($config['TELEGRAM_RECEIVE_MODE'] ?? null) !== 'webhook') {
                    throw new ConfigurationException('Для регистрации укажите TELEGRAM_RECEIVE_MODE=webhook в config/bot.ini.');
                }
                $secret = $config['TELEGRAM_WEBHOOK_SECRET'] ?? null;
                if (!is_string($secret) || preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $secret) !== 1) {
                    throw new ConfigurationException('Укажите TELEGRAM_WEBHOOK_SECRET: 1–256 символов A–Z, a–z, 0–9, _ или -.');
                }

                $bot->setWebhook($this->url, $secret);

                return 'Webhook зарегистрирован.';
            }
            if ($this->action === 'delete') {
                $bot->deleteWebhook();

                return 'Webhook удалён. Ожидающие обновления сохранены.';
            }

            $info = $bot->getWebhookInfo();
            $status = $info->configured ? 'Webhook зарегистрирован.' : 'Webhook не зарегистрирован.';
            $pending = 'Ожидающих обновлений: ' . $info->pendingUpdateCount . '.';
            $delivery = match (true) {
                $info->lastErrorDate !== null => 'Последняя ошибка доставки: ' . gmdate('Y-m-d H:i:s', $info->lastErrorDate) . ' UTC.',
                $info->hasDeliveryError => 'Есть ошибка доставки. Дата неизвестна.',
                default => 'Сведений об ошибках доставки нет.',
            };

            return "$status $pending $delivery";
        } catch (BotApiException $exception) {
            $hint = match ($exception->apiCode ?? $exception->httpStatus) {
                400 => $this->action === 'set'
                    ? 'Проверьте доступность адреса webhook и его сертификат TLS.'
                    : 'Проверьте параметры бота.',
                401, 403 => 'Проверьте токен бота.',
                429 => 'Повторите команду позже.',
                default => 'Проверьте доступность Bot API и повторите команду.',
            };
            throw new StartupException(sprintf(
                'Bot API отклонил команду webhook (HTTP %d, API %s). %s',
                $exception->httpStatus,
                $exception->apiCode === null ? 'нет' : (string) $exception->apiCode,
                $hint,
            ));
        } catch (BotTransportException) {
            throw new StartupException('Сетевая ошибка Bot API. Проверьте соединение и сертификаты, затем запросите статус webhook.');
        } catch (UnexpectedResponseException) {
            throw new StartupException('Bot API вернул некорректный ответ. Повторите проверку статуса webhook.');
        }
    }

    private static function isValidUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (
            $parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && !in_array($parts['port'], [443, 80, 88, 8443], true))
            || preg_match('~\Ahttps://[^/]+:(?:/|\z)~iD', $url) === 1
            || preg_match('#\A(?:[A-Za-z0-9._~!$&\'()*+,;=:@/-]|%[0-9A-Fa-f]{2})*\z#D', $parts['path'] ?? '') !== 1
        ) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        // RU: Проверяем только форму DNS-имени и исключаем локальные и специальные суффиксы.
        // EN: Check the DNS name syntax and exclude local and special-use suffixes.
        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/\.[a-z][a-z0-9-]*\.?\z/D', $host) === 1
            && preg_match('/\.(?:localhost|localdomain|local|internal|lan|corp|home|home\.arpa|onion|test|invalid|example|alt)\.?\z/D', $host) !== 1;
    }
}
