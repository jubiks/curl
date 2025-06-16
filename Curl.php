<?php

/**
 * Класс Curl - оболочка для расширения cURL в PHP.
 * Упрощает настройку и выполнение HTTP-запросов, включая POST-запросы с данными в формате JSON,
 * управление аутентификацией, прокси, cookies и заголовками.
 */
class Curl
{
    /**
     * @var array|false Данные для отправки в теле POST-запроса (в формате JSON).
     */
    private $fields = false;

    /**
     * @var string|null Строка GET-параметров для URL.
     */
    private $queryString = null;

    /**
     * @var string|false Данные для Basic-аутентификации в формате "логин:пароль".
     */
    private $auth = false;

    /**
     * @var int Таймаут выполнения запроса в секундах.
     */
    private $timeout = 20;

    /**
     * @var string|false Адрес прокси-сервера.
     */
    private $proxyAddr = false;

    /**
     * @var int|false Порт прокси-сервера.
     */
    private $proxyPort = false;

    /**
     * @var int Тип прокси-сервера (константа CURLPROXY_*).
     */
    private $proxyType = CURLPROXY_HTTP;

    /**
     * @var string|false Данные для аутентификации на прокси-сервере в формате "логин:пароль".
     */
    private $proxyAuth = false;

    /**
     * @var string|false Строка User-Agent, отправляемая в запросе.
     */
    private $userAgent = false;

    /**
     * @var string|false HTTP-реферер, отправляемый в запросе.
     */
    private $referer = false;

    /**
     * @var float Время выполнения последнего запроса в миллисекундах.
     */
    private $queryTime = 0;

    /**
     * @var string Путь к файлу с сертификатами CA (cacert.pem).
     */
    private $cacertPath;

    /**
     * @var string Путь к файлу для хранения cookies (cookie-jar).
     */
    private $cookiePath;

    /**
     * @var array Массив с заголовками последнего ответа.
     */
    private $headers;

    /**
     * @var array|null Массив с информацией об ошибке, если она произошла.
     */
    private $error;

    /**
     * @var resource cURL-ресурс (хэндл).
     */
    private $curl;

    /**
     * Конструктор класса. Инициализирует cURL-сессию и устанавливает путь к файлу сертификатов.
     */
    public function __construct() {
        $this->curl = curl_init();
        // Устанавливаем путь к файлу сертификатов относительно корня сайта.
        $this->cacertPath = $_SERVER['DOCUMENT_ROOT'] . '/cacert.pem';
    }

    /**
     * Добавляет данные для отправки.
     *
     * @param array $fields Ассоциативный массив с данными.
     * @param bool $toGet Если true, данные будут преобразованы в GET-строку. Иначе - в JSON для POST-запроса.
     */
    public function addFields(array $fields, $toGet = false) {
        if($toGet) {
            $this->queryString = http_build_query($fields);
        } else {
            $this->fields = json_encode($fields);
        }
    }

    /**
     * Устанавливает данные для HTTP Basic-аутентификации.
     *
     * @param string $login Имя пользователя.
     * @param string $password Пароль.
     */
    public function setAuth($login, $password) {
        $this->auth = "{$login}:{$password}";
    }

    /**
     * Устанавливает таймаут для cURL-запроса.
     *
     * @param int $timeout Время ожидания в секундах. По умолчанию 20.
     */
    public function setTimeout($timeout) {
        $this->timeout = intval($timeout) ?: 20;
    }

    /**
     * Устанавливает адрес и порт прокси-сервера.
     *
     * @param string $addr Адрес прокси. Может содержать порт через двоеточие (e.g., "127.0.0.1:8080").
     * @param int|false $port Порт прокси. Если не указан, будет извлечен из $addr.
     */
    public function setProxy($addr, $port = false) {
        if($port) {
            $this->proxyAddr = $addr;
            $this->proxyPort = $port;
        } else {
            $arAddr = explode(':', $addr);
            $this->proxyAddr = $arAddr[0];
            $this->proxyPort = $arAddr[1] ?: null;
        }
    }

    /**
     * Устанавливает тип прокси-сервера.
     *
     * @param string $type Тип прокси ('http', 'socks4', 'socks5' и т.д.).
     */
    public function setProxyType($type) {
        $proxyTypes = [
            'http' => CURLPROXY_HTTP,
            'socks4' => CURLPROXY_SOCKS4,
            'socks4a' => CURLPROXY_SOCKS4A,
            'socks5' => CURLPROXY_SOCKS5,
            'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
        ];

        if(key_exists($type, $proxyTypes)) {
            $this->proxyType = $proxyTypes[$type];
        } elseif(in_array($type, $proxyTypes)) {
            $this->proxyType = $type;
        }
    }

    /**
     * Устанавливает данные для аутентификации на прокси-сервере.
     *
     * @param string $login Имя пользователя.
     * @param string $password Пароль.
     */
    public function setProxyAuth($login, $password) {
        $this->proxyAuth = "{$login}:{$password}";
    }

    /**
     * Устанавливает заголовок Referer.
     *
     * @param string $referer URL-адрес реферера.
     */
    public function setReferer($referer) {
        $this->referer = $referer;
    }

    /**
     * Устанавливает путь к файлу для хранения и чтения cookies.
     * Если путь или файл не существуют, метод попытается их создать.
     *
     * @param string $path Полный путь к файлу cookie.
     */
    public function setCookiePath($path) {
        if(!file_exists(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        if(!file_exists($path)) {
            file_put_contents($path, "");
        }

        if(file_exists($path)) {
            $this->cookiePath = $path;
        }
    }

    /**
     * Выполняет cURL-запрос с заданными параметрами.
     *
     * @param string $url URL для запроса.
     * @return string|null Тело ответа в случае успеха, null в случае ошибки.
     */
    public function request($url) {
        $curlStart = microtime(true);

        // Устанавливаем User-Agent по умолчанию, если он не был задан ранее.
        $userAgent = !$this->userAgent ? "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36" : $this->userAgent;
        
        // Добавляем к URL GET-параметры, если они были установлены
        if ($this->queryString) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $this->queryString;
        }

        // --- Основные настройки cURL ---
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1); // Вернуть ответ в виде строки, а не выводить в stdout
        curl_setopt($this->curl, CURLOPT_HEADER, 1); // Включить заголовки в ответ
        curl_setopt($this->curl, CURLOPT_TIMEOUT, $this->timeout); // Установить таймаут
        curl_setopt($this->curl, CURLOPT_USERAGENT, $userAgent);
        
        // Настройки SSL
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false); // Отключаем проверку SSL-сертификата (не рекомендуется в production)
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, false); // Отключаем проверку имени хоста в сертификате
        curl_setopt($this->curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2); // Используем TLS 1.2

        // Настройки HTTP
        curl_setopt($this->curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($this->curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Использовать только IPv4
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, [ // Отправляем кастомные заголовки
            "Content-Type: application/json; charset=utf8",
            "Accept: application/json",
            "Connection: keep-alive"
        ]);
        
        // Путь к файлу сертификата
        if(file_exists($this->cacertPath))
            curl_setopt($this->curl, CURLOPT_CAINFO, $this->cacertPath);

        // Устанавливаем реферер, если он задан
        if($this->referer)
            curl_setopt($this->curl, CURLOPT_REFERER, $this->referer);
        
        // Настройки Basic-аутентификации
        if($this->auth) {
            curl_setopt($this->curl, CURLOPT_USERPWD, $this->auth);
            curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        // Настройки POST-запроса с данными
        if($this->fields) {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $this->fields);
        }

        // Настройки прокси-сервера
        if($this->proxyAddr && $this->proxyPort) {
            curl_setopt($this->curl, CURLOPT_PROXY, $this->proxyAddr);
            curl_setopt($this->curl, CURLOPT_PROXYPORT, $this->proxyPort);
            curl_setopt($this->curl, CURLOPT_HTTPPROXYTUNNEL, 1); // Проксировать через HTTP туннель

            if($this->proxyType)
                curl_setopt($this->curl, CURLOPT_PROXYTYPE, $this->proxyType);

            if($this->proxyAuth)
                curl_setopt($this->curl, CURLOPT_PROXYUSERPWD, $this->proxyAuth);
        }
        
        // Настройки для работы с cookies
        if($this->cookiePath) {
            curl_setopt($this->curl, CURLOPT_COOKIEJAR, $this->cookiePath); // Файл для записи cookies после сессии
            curl_setopt($this->curl, CURLOPT_COOKIEFILE, $this->cookiePath); // Файл для чтения cookies
        }

        // Выполняем запрос
        $response = curl_exec($this->curl);

        $error = null;
        if($response === false) {
            // Если запрос не удался, сохраняем информацию об ошибке
            $this->error = [
                'code' => $this->getResponseError(curl_errno($this->curl)),
                'message' => curl_error($this->curl)
            ];
        }

        // Разделяем заголовки и тело ответа
        $header_size = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        $header_string = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        // --- Парсинг заголовков ---
        $headers = [];
        $header_rows = explode("\r\n", trim($header_string));
        foreach($header_rows as $row) {
            // Пропускаем пустые строки
            if (empty($row)) continue;
            // Статус-строка (HTTP/1.1 200 OK)
            if (strpos(strtolower($row), 'http/') === 0) {
                $headers['http-status'] = $row;
                continue;
            }
            // Обычные заголовки
            $parts = explode(':', $row, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }
        $this->headers = $headers;
        // --- Конец парсинга заголовков ---

        $curlEnd = microtime(true);
        // Вычисляем время выполнения запроса в миллисекундах
        $this->queryTime = ($curlEnd - $curlStart) * 1000;

        return $this->error ? null : $body;
    }

    /**
     * Возвращает заголовки последнего HTTP-ответа.
     *
     * @return array|null Массив заголовков или null, если запрос не был выполнен.
     */
    public function getHeaders() {
        return $this->headers ?: null;
    }

    /**
     * Возвращает информацию об ошибке последнего запроса.
     *
     * @return array|null Массив с кодом и сообщением об ошибке или null, если ошибок не было.
     */
    public function getError() {
        return $this->error ?: null;
    }

    /**
     * Возвращает время выполнения последнего запроса.
     *
     * @return float Время в миллисекундах.
     */
    public function getLastQueryTime() {
        return $this->queryTime;
    }

    /**
     * Преобразует код ошибки cURL в текстовое представление.
     *
     * @param int $errno Код ошибки curl_errno().
     * @return string Текстовое представление ошибки.
     */
    private function getResponseError($errno) {
        // Ассоциативный массив кодов ошибок cURL
        $curl_error_codes = [
            1 => 'CURLE_UNSUPPORTED_PROTOCOL',
            2 => 'CURLE_FAILED_INIT',
            3 => 'CURLE_URL_MALFORMAT',
            4 => 'CURLE_URL_MALFORMAT_USER',
            5 => 'CURLE_COULDNT_RESOLVE_PROXY',
            6 => 'CURLE_COULDNT_RESOLVE_HOST',
            7 => 'CURLE_COULDNT_CONNECT',
            8 => 'CURLE_FTP_WEIRD_SERVER_REPLY',
            9 => 'CURLE_REMOTE_ACCESS_DENIED',
            11 => 'CURLE_FTP_WEIRD_PASS_REPLY',
            13 => 'CURLE_FTP_WEIRD_PASV_REPLY',
            14 =>'CURLE_FTP_WEIRD_227_FORMAT',
            15 => 'CURLE_FTP_CANT_GET_HOST',
            17 => 'CURLE_FTP_COULDNT_SET_TYPE',
            18 => 'CURLE_PARTIAL_FILE',
            19 => 'CURLE_FTP_COULDNT_RETR_FILE',
            21 => 'CURLE_QUOTE_ERROR',
            22 => 'CURLE_HTTP_RETURNED_ERROR',
            23 => 'CURLE_WRITE_ERROR',
            25 => 'CURLE_UPLOAD_FAILED',
            26 => 'CURLE_READ_ERROR',
            27 => 'CURLE_OUT_OF_MEMORY',
            28 => 'CURLE_OPERATION_TIMEDOUT',
            30 => 'CURLE_FTP_PORT_FAILED',
            31 => 'CURLE_FTP_COULDNT_USE_REST',
            33 => 'CURLE_RANGE_ERROR',
            34 => 'CURLE_HTTP_POST_ERROR',
            35 => 'CURLE_SSL_CONNECT_ERROR',
            36 => 'CURLE_BAD_DOWNLOAD_RESUME',
            37 => 'CURLE_FILE_COULDNT_READ_FILE',
            38 => 'CURLE_LDAP_CANNOT_BIND',
            39 => 'CURLE_LDAP_SEARCH_FAILED',
            41 => 'CURLE_FUNCTION_NOT_FOUND',
            42 => 'CURLE_ABORTED_BY_CALLBACK',
            43 => 'CURLE_BAD_FUNCTION_ARGUMENT',
            45 => 'CURLE_INTERFACE_FAILED',
            47 => 'CURLE_TOO_MANY_REDIRECTS',
            48 => 'CURLE_UNKNOWN_TELNET_OPTION',
            49 => 'CURLE_TELNET_OPTION_SYNTAX',
            51 => 'CURLE_PEER_FAILED_VERIFICATION',
            52 => 'CURLE_GOT_NOTHING',
            53 => 'CURLE_SSL_ENGINE_NOTFOUND',
            54 => 'CURLE_SSL_ENGINE_SETFAILED',
            55 => 'CURLE_SEND_ERROR',
            56 => 'CURLE_RECV_ERROR',
            58 => 'CURLE_SSL_CERTPROBLEM',
            59 => 'CURLE_SSL_CIPHER',
            60 => 'CURLE_SSL_CACERT',
            61 => 'CURLE_BAD_CONTENT_ENCODING',
            62 => 'CURLE_LDAP_INVALID_URL',
            63 => 'CURLE_FILESIZE_EXCEEDED',
            64 => 'CURLE_USE_SSL_FAILED',
            65 => 'CURLE_SEND_FAIL_REWIND',
            66 => 'CURLE_SSL_ENGINE_INITFAILED',
            67 => 'CURLE_LOGIN_DENIED',
            68 => 'CURLE_TFTP_NOTFOUND',
            69 => 'CURLE_TFTP_PERM',
            70 => 'CURLE_REMOTE_DISK_FULL',
            71 => 'CURLE_TFTP_ILLEGAL',
            72 => 'CURLE_TFTP_UNKNOWNID',
            73 => 'CURLE_REMOTE_FILE_EXISTS',
            74 => 'CURLE_TFTP_NOSUCHUSER',
            75 => 'CURLE_CONV_FAILED',
            76 => 'CURLE_CONV_REQD',
            77 => 'CURLE_SSL_CACERT_BADFILE',
            78 => 'CURLE_REMOTE_FILE_NOT_FOUND',
            79 => 'CURLE_SSH',
            80 => 'CURLE_SSL_SHUTDOWN_FAILED',
            81 => 'CURLE_AGAIN',
            82 => 'CURLE_SSL_CRL_BADFILE',
            83 => 'CURLE_SSL_ISSUER_ERROR',
            84 => 'CURLE_FTP_PRET_FAILED',
            85 => 'CURLE_RTSP_CSEQ_ERROR',
            86 => 'CURLE_RTSP_SESSION_ERROR',
            87 => 'CURLE_FTP_BAD_FILE_LIST',
            88 => 'CURLE_CHUNK_FAILED'
        ];
        return $curl_error_codes[$errno] ?: 'UNKNOWN_ERROR';
    }

    /**
     * Деструктор класса. Закрывает cURL-сессию для освобождения ресурсов.
     */
    public function __destruct() {
        if ($this->curl) {
            curl_close($this->curl);
        }
    }
}
