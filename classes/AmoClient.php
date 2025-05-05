<?php
declare(strict_types=1);

/**
 *
 */
class AmoClient
{
    private const ERRORS = [
        400 => 'Bad request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not found',
        500 => 'Internal server error',
        502 => 'Bad gateway',
        503 => 'Service unavailable'
    ];

    private string $subDomain;
    private string $clientId;
    private string $clientSecret;
    private string $code;
    private string $redirectUri;
    private string $accessToken;
    private string $tokenFile;

    /**
     * @param $sub_domain
     * @param $client_id
     * @param $client_secret
     * @param $code
     * @param $redirect_uri
     * @throws Exception
     */
    public function __construct($sub_domain, $client_id, $client_secret, $code, $redirect_uri)
    {
        $this->subDomain = $sub_domain;
        $this->clientId = $client_id;
        $this->clientSecret = $client_secret;
        $this->code = $code;
        $this->redirectUri = $redirect_uri;
        $this->tokenFile = 'files/TOKEN.txt';

        if (file_exists($this->tokenFile)) {
            $expiresIn = json_decode(file_get_contents($this->tokenFile), false, 512, JSON_THROW_ON_ERROR)->{'expires_in'};
            $this->accessToken = json_decode(file_get_contents($this->tokenFile), false, 512, JSON_THROW_ON_ERROR)->{'access_token'};
            if ($expiresIn < time()) {
                $this->getToken(true);
            }
        }
        else {
            $this->getToken();
        }
    }

    /**
     * @param bool $refresh
     * @return void
     * @throws Exception
     */
    public function getToken(bool $refresh = false): void
    {
        $link = 'https://' . $this->subDomain . '.amocrm.ru/oauth2/access_token'; //Формируем URL для запроса

        /** Соберем данные для запроса */
        $data = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => ($refresh)
                ? 'refresh_token'
                : 'authorization_code',
            'redirect_uri'  => $this->redirectUri];

        if ($refresh) {
            $data['refresh_token'] = json_decode(file_get_contents($this->tokenFile), false, 512, JSON_THROW_ON_ERROR)->{'refresh_token'};
        }
        else {
            $data['code'] = $this->code;
        }

        $curl = curl_init(); //Сохраняем дескриптор сеанса cURL
        /** Устанавливаем необходимые опции для сеанса cURL  */
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-oAuth-client/1.0');
        curl_setopt($curl, CURLOPT_URL, $link);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        $out = curl_exec($curl); //Инициируем запрос к API и сохраняем ответ в переменную
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $code = (int)$code;
        try {
            if ($code < 200 || $code > 204) {
                throw new RuntimeException(self::ERRORS[$code] ?? PHP_EOL . 'Ошибка ' . __LINE__ . ': Undefined error', $code);
            }
        } catch (Exception $exc) {
            echo $out;
            die(PHP_EOL . 'Ошибка ' . __LINE__ . ': ' . $exc->getMessage() . PHP_EOL . 'Код ошибки:' . $exc->getCode());
        }

        $response = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        $this->accessToken = $response['access_token'];
        $token = [
            'access_token'  => $response['access_token'], //Access токен
            'refresh_token' => $response['refresh_token'], //Refresh токен
            'token_type'    => $response['token_type'], //Тип токена
            'expires_in'    => time() + $response['expires_in'] //Через сколько действие токена истекает
        ];

        file_put_contents($this->tokenFile, json_encode($token, JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $link
     * @param string $method
     * @param array $post_fields
     * @return string
     * @throws Exception
     */
    public function curlRequest(string $link, string $method, array $post_fields = []): string
    {
        /** Формируем заголовки */
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-oAuth-client/1.0');
        curl_setopt($curl, CURLOPT_URL, $link);
        if ($method === 'POST' || $method === 'PATCH') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($post_fields, JSON_THROW_ON_ERROR));
        }
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        $out = curl_exec($curl); //Инициируем запрос к API и сохраняем ответ в переменную
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $code = (int)$code;
        try {
            #Если код ответа не равен 200 или 204 - возвращаем сообщение об ошибке
            if ($code !== 200 && $code !== 204) {
                throw new RuntimeException(self::ERRORS[$code] ?? 'Undescribed error', $code);
            }
        } catch (Exception $exc) {
            $this->error('Ошибка: ' . $exc->getMessage() . PHP_EOL . 'Код ошибки: ' . $exc->getCode() . $link);
        }
        return $out;
    }


    /**
     * @param string $service
     * @param array $params
     * @return mixed
     * @throws Exception
     */
    public function requestApiGet(string $service, array $params = []): mixed
    {
        $result = '';
        try {
            $url = 'https://' . $this->subDomain . '.amocrm.ru/api/v4/' . $service;
            $url .= ($params !== [])
                ? '?' . http_build_query($params)
                : '';
            $result = json_decode($this->curlRequest($url, 'GET'), true, 512, JSON_THROW_ON_ERROR);
            // Проверка на пустой ответ
            if (empty($result)) {
                throw new RuntimeException('Пустой ответ от API для сервиса: ' . $service);
            }
            usleep(250000);
        } catch (Exception $exception) {
            $this->error($exception);
        }
        return $result;
    }

    /**
     * @param string $service
     * @param array $params
     * @return mixed
     * @throws Exception
     */
    public function requestApiV2Get(string $service, array $params = [])
    {
        $result = '';
        try {
            $url = 'https://' . $this->subDomain . '.amocrm.ru/api/v2/' . $service;
            $url .= ($params !== [])
                ? '?' . http_build_query($params)
                : '';
            $result = json_decode($this->curlRequest($url, 'GET'), true, 512, JSON_THROW_ON_ERROR);
            // Проверка на пустой ответ
            if (empty($result)) {
                throw new RuntimeException('Пустой ответ от API для сервиса: ' . $service);
            }
            usleep(250000);
        } catch (Exception $exception) {
            $this->error($exception);
        }
        return $result;
    }

    /**
     * @param string $service
     * @param array $params
     * @param string $method
     * @return array
     */
    public function requestApiV2Post(string $service, array $params = [], string $method = 'POST'): array
    {
        $result = [];
        $url = 'https://' . $this->subDomain . '.amocrm.ru/api/v2/' . $service;
        try {
            $result = json_decode($this->curlRequest($url, $method, $params), true, 512, JSON_THROW_ON_ERROR);
            usleep(250000);

        } catch (Exception $exception) {
            $this->error($exception);
        }
        return $result;
    }

    /**
     * @param string $service
     * @param array $params
     * @param string $method
     * @return array
     */
    public function requestApiPost(string $service, array $params = [], string $method = 'POST'): array
    {
        $result = [];
        $url = "https://$this->subDomain.amocrm.ru/api/v4/$service";
        try {
            $result = json_decode($this->curlRequest($url, $method, $params), true, 512, JSON_THROW_ON_ERROR);
            usleep(250000);

        } catch (Exception $exception) {
            $this->error($exception);
        }
        return $result;
    }

    /**
     * @param $error
     * @return void
     */
    public function error($error): void
    {
        if (is_array($error)) {
            $error = print_r($error, true); // Преобразуем массив в строку
        }
        file_put_contents('ERROR_LOG.txt', $error);
    }








    /**
     * Добавляет примечание к сделке или контакту
     *
     * @param int $entityId
     * @param string $entityType "leads" или "contacts"
     * @param string $noteText
     * @return void
     */
    public function addNote(int $entityId, string $entityType, string $noteText): void
    {
        $supported = ['leads', 'contacts'];
        if (!in_array($entityType, $supported, true)) {
            throw new InvalidArgumentException("Unsupported entity type: $entityType");
        }

        $data = [
            [
                'note_type' => 'common',
                'params' => ['text' => $noteText]
            ]
        ];

        $this->requestApiPost("$entityType/$entityId/notes", $data);
    }

}



