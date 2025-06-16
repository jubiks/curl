<?php

class Curl
{
    private $fields = false;
    private $queryString = null;
    private $auth = false;
    private $timeout = 20;
    private $proxyAddr = false;
    private $proxyPort = false;
    private $proxyType = CURLPROXY_HTTP;
    private $proxyAuth = false;
    private $userAgent = false;
    private $referer = false;
    private $queryTime = 0;
    private $cacertPath;
    private $cookiePath;
    private $headers;
    private $error;
    private $curl;

    public function __construct() {
        $this->curl = curl_init();
        $this->cacertPath = $_SERVER['DOCUMENT_ROOT'] . '/cacert.pem';
    }

    public function addFields(array $fields, $toGet = false) {
        if($toGet) {
            $this->queryString = http_build_query($fields);
        } else {
            $this->fields = json_encode($fields);
        }
    }

    public function setAuth($login,$password) {
        $this->auth = "{$login}:{$password}";
    }

    public function setTimeout($timeout) {
        $this->timeout = intval($timeout) ?: 20;
    }

    public function setProxy($addr, $port = false) {
        if($port) {
            $this->proxyAddr = $addr;
            $this->proxyPort = $port;
        } else {
            $arAddr = explode(':',$addr);
            $this->proxyAddr = $arAddr[0];
            $this->proxyPort = $arAddr[1] ?: null;
        }
    }

    public function setProxyType($type) {
        $proxyTypes = [
            'http' => CURLPROXY_HTTP,
            //'http1' => CURLPROXY_HTTP_1_0,
            //'https' => CURLPROXY_HTTPS,
            //'https2' => CURLPROXY_HTTPS2,
            'socks4' => CURLPROXY_SOCKS4,
            'socks4a' => CURLPROXY_SOCKS4A,
            'socks5' => CURLPROXY_SOCKS5,
            'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
        ];

        if(key_exists($type,$proxyTypes)) {
            $this->proxyType = $proxyTypes[$type];
        } elseif(in_array($type, $proxyTypes)) {
            $this->proxyType = $type;
        }
    }

    public function setProxyAuth($login,$password) {
        $this->proxyAuth = "{$login}:{$password}";
    }

    public function setReferer($referer) {
        $this->referer = $referer;
    }

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

    public function request($url) {
        $curlStart = microtime(true);

        $userAgent = !$this->userAgent ? $this->userAgent : "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36";

        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($this->curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($this->curl, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($this->curl, CURLOPT_VERBOSE, false); // отключил вывод заголовков, иначе - true
        curl_setopt($this->curl, CURLOPT_HEADER, 1);
        curl_setopt($this->curl, CURLOPT_FORBID_REUSE, false);
        curl_setopt($this->curl, CURLOPT_FRESH_CONNECT, false);
        curl_setopt($this->curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json; charset=utf8",
            "Content-Encoding: gzip",
            "X-Client-Name: site",
            "Connection: keep-alive"
        ));
        //curl_setopt($curl, CURLOPT_ENCODING , '');

        if(file_exists($this->cacertPath))
            curl_setopt($this->curl, CURLOPT_CAINFO, $this->cacertPath);

        if($this->referer)
            curl_setopt($this->curl, CURLOPT_REFERER, $this->referer);

        if($this->auth) {
            curl_setopt($this->curl, CURLOPT_USERPWD, $this->auth);
            curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        if($this->fields) {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $this->fields);
        }

        if($this->proxyAddr && $this->proxyPort) {
            curl_setopt($this->curl, CURLOPT_PROXY, $this->proxyAddr);
            curl_setopt($this->curl, CURLOPT_PROXYPORT, $this->proxyPort);
            curl_setopt($this->curl, CURLOPT_HTTPPROXYTUNNEL, 1);

            if($this->proxyType)
                curl_setopt($this->curl, CURLOPT_PROXYTYPE, $this->proxyType);

            if($this->proxyAuth)
                curl_setopt($this->curl, CURLOPT_PROXYUSERPWD, $this->proxyAuth);
        }

        if($this->cookiePath) {
            //CURLOPT_COOKIEJAR - файл, куда пишутся куки после закрытия коннекта, например после curl_close()
            curl_setopt($this->curl, CURLOPT_COOKIEJAR, $this->cookiePath);
            //CURLOPT_COOKIEFILE - файл, откуда читаются куки.
            curl_setopt($this->curl, CURLOPT_COOKIEFILE, $this->cookiePath);
        }

        $response = curl_exec($this->curl);

        $error = null;
        if($response === false) {
            $this->error = [
                'code' => $this->getResponseError(curl_errno($this->curl)),
                'message' => curl_error($this->curl)
            ];
        }

        $header_size = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        $header_string = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        $i = 0;
        $j = 0;
        $headers = [];
        $header_rows = explode(PHP_EOL, $header_string);
        $header_rows = array_filter($header_rows, 'trim');
        foreach((array)$header_rows as $hr){
            $colonpos = strpos($hr, ':');
            $key = $colonpos !== false ? substr($hr, 0, $colonpos) : (int)$i++;
            $headers[$key] = $colonpos !== false ? trim(substr($hr, $colonpos+1)) : $hr;
        }
        foreach((array)$headers as $key => $val){
            $vals = explode(';', $val);
            if(count($vals) >= 2){
                unset($headers[$key]);
                foreach($vals as $vk => $vv){
                    $equalpos = strpos($vv, '=');
                    $vkey = $equalpos !== false ? trim(substr($vv, 0, $equalpos)) : (int)$j++;
                    $headers[$key][$vkey] = $equalpos !== false ? trim(substr($vv, $equalpos+1)) : $vv;
                }
            }

            if(strpos($val,'HTTP') !== false) {
                $headers = array_merge(['http-status' => $val], $headers);
                //$headers['Http-code-status'] = $val;
                unset($headers[$key]);
            }
        }

        $this->headers = $headers;

        $curlEnd = microtime(true);

        // Время выполнения текущего cURL запроса в миллисекундах
        $this->queryTime = ($curlEnd - $curlStart) * 1000;

        return $error ? null : $body;
    }

    public function getHeaders() {
        return $this->headers ?: null;
    }

    public function getError() {
        return $this->error ?: null;
    }

    public function getLastQueryTime() {
        return $this->queryTime;
    }

    private function getResponseError($errno) {
        $curl_error_codes = array(
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
        );

        return $curl_error_codes[$errno] ?: 'UNKNOWN';
    }

    public function __destruct() {
        curl_close($this->curl);
    }
}