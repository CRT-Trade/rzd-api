<?php

namespace Rzd;

use Curl\Curl;
use Exception;
use RuntimeException;

class Query
{
    private $config;
    private $curl;

    /**
     * Query constructor.
     *
     * @param Config $config
     * @throws Exception
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->curl = new Curl();
    }

    /**
     * Отправка и получение данных
     *
     * @param  string $path   путь к сайту
     * @param  array  $params массив данных если необходимы параметры
     * @param  string $method метод отправки данных
     * @return mixed          данные страницы
     * @throws Exception
     */
    public function send($path, array $params = [], $method = 'post')
    {
        $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rzd_cookie';

        $proxy = $this->config->getProxy();
        if ($proxy['server']) {
            $this->curl->setProxy($proxy['server'], $proxy['port'], $proxy['username'], $proxy['password']);
            $this->curl->setProxyTunnel();
        }

        if ($userAgent = $this->config->getUserAgent()) {
            $this->curl->setUserAgent($userAgent);
        }

        if ($referer = $this->config->getReferer()) {
            $this->curl->setReferer($referer);
        }

        $this->curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $this->curl->setCookieFile($cookieFile);
        $this->curl->setCookieJar($cookieFile);
        $this->run($path, $params, $method);

        return $this->curl->getResponse();
    }

    /**
     * Запрашивает данные
     *
     * @param  string $path   путь к странице
     * @param  array  $params массив параметров
     * @param  string $method тип запроса
     * @return Curl           массив данных
     * @throws Exception
     */
    protected function run($path, array $params, $method): Curl
    {
        $count = 0;

        do {
            if (! empty($cookies) && ! empty($session)){
                foreach ($cookies as $key=>$value){
                    $this->curl->setCookie($key, $value);
                }

                $params += ['rid' => $session];
            }

            $this->curl->$method($path, $params);

            $response = $this->curl->getResponse();

            if (empty($response) || ! empty($response->error)) {
                throw new RuntimeException('Ошибка: Не удалось получить данные');
            }

            if ($this->isJson($response)) {
                $response = json_decode($response);
            }

            $result = $response->result ?? $response->type ?? 'OK';

            switch ($result) {
                case 'RID':
                case 'REQUEST_ID':
                    $session = $this->getRid($response);
                    $cookies = $this->curl->getResponseCookies();
                    sleep(1);
                    break 1;

                case 'OK':
                    if (isset($response->tp[0]->msgList[0]->message)) {
                        $this->curl->close();
                        throw new RuntimeException($response->tp[0]->msgList[0]->message);
                    }
                    break 2;

                default:
                   $this->curl->close();
                   throw new RuntimeException($response->message ?? 'Ошибка разбора XML');
            }

            $count++;
        } while ($count < 5);

        return $this->curl;
    }

    /**
     * Получает уникальный ключа RID
     *
     * @param  string $json данные
     * @return string       уникальный ключ
     * @throws Exception
     */
    protected function getRid($json): string
    {
        foreach (['rid', 'RID'] as $rid){
            if (isset($json->$rid)) {
                return $json->$rid;
            }
        }

        throw new RuntimeException('Ошибка: Не найден уникальный ключ');
    }

    /**
     * Проверка является ли строка валидным json-объектом
     *
     * @param  string  $string проверяемая строка
     * @return boolean         результат проверки
     */
    protected function isJson($string): bool
    {
        if (! is_string($string)) {
            return false;
        }

        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Закрывает соединение
     */
    public function __destruct()
    {
        $this->curl->close();
    }
}
