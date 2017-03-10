<?php

namespace Airbrake;

/**
 * Airbrake exception notifier.
 */
class Notifier
{
    /**
     * @var string
     */
    private $noticesUrl;

    /**
     * @var array
     */
    private $opt;

    /**
     * @var callable[]
     */
    private $filters = [];

    /**
     * Http client
     * @var Http\ClientInterface
     */
    private $client;

    /**
     * Constructor
     *
     * Available options are:
     *  - projectId     project id
     *  - projectKey    project key
     *  - host          airbrake api host e.g.: 'api.airbrake.io' or 'http://errbit.example.com'
     *  - appVersion
     *  - environment
     *  - rootDirectory
     *  - httpClient    which http client to use: "default", "curl" or "guzzle"
     *
     * @param array $opt the options
     * @throws \Airbrake\Exception
     */
    public function __construct($opt = [])
    {
        if (empty($opt['projectId']) || empty($opt['projectKey'])) {
            throw new Exception('both projectId and projectKey are required');
        }

        $this->opt = array_merge([
            'host' => 'api.airbrake.io',
        ], $opt);

        if (!empty($opt['rootDirectory'])) {
            $this->addFilter(function ($notice) {
                return $this->rootDirectoryFilter($notice);
            });
        }

        $handler = (isset($this->opt['httpClient']) ? $this->opt['httpClient'] : null);
        $this->client = Http\Factory::createHttpClient($handler);
    }

    /**
     * Appends filter to the list.
     *
     * Filter is a callback that accepts notice. Filter can modify passed
     * notice or return null if notice must be ignored.
     *
     * @param callable $filter Filter callback
     */
    public function addFilter($filter)
    {
        $this->filters[] = $filter;
    }

    private function backtrace($exc)
    {
        $backtrace = [];
        $backtrace[] = [
            'file' => $exc->getFile(),
            'line' => $exc->getLine(),
            'function' => '',
        ];
        $trace = $exc->getTrace();
        foreach ($trace as $frame) {
            $func = $frame['function'];
            if (isset($frame['class']) && isset($frame['type'])) {
                $func = $frame['class'] . $frame['type'] . $func;
            }
            if (count($backtrace) > 0) {
                $backtrace[count($backtrace) - 1]['function'] = $func;
            }

            $backtrace[] = [
                'file' => isset($frame['file']) ? $frame['file'] : '',
                'line' => isset($frame['line']) ? $frame['line'] : 0,
                'function' => '',
            ];
        }
        return $backtrace;
    }

    /**
     * Builds Airbrake notice from exception.
     *
     * @param \Throwable|\Exception $exc Exception or class that implements similar interface.
     */
    public function buildNotice($exc)
    {
        $error = [
            'type' => get_class($exc),
            'message' => $exc->getMessage(),
            'backtrace' => $this->backtrace($exc),
        ];

        $context = [
            'notifier' => [
                'name' => 'phpbrake',
                'version' => '0.2.2',
                'url' => 'https://github.com/airbrake/phpbrake',
            ],
            'os' => php_uname(),
            'language' => 'php ' . phpversion(),
        ];
        if (!empty($this->opt['appVersion'])) {
            $context['version'] = $this->opt['appVersion'];
        }
        if (!empty($this->opt['environment'])) {
            $context['environment'] = $this->opt['environment'];
        }
        if (($hostname = gethostname()) !== false) {
            $context['hostname'] = $hostname;
        }
        if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
            $scheme = isset($_SERVER['HTTPS']) ? 'https' : 'http';
            $context['url'] = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $context['userAgent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        $notice = [
            'errors' => [$error],
            'context' => $context,
            'environment' => $this->getEnvironment(),
        ];
        if (!empty($_REQUEST)) {
            $notice['params'] = $_REQUEST;
        }
        if (!empty($_SESSION)) {
            $notice['session'] = $_SESSION;
        }

        return $notice;
    }

    /**
     * Posts data to the URL.
     */
    protected function postNotice($url, $data)
    {
        return $this->client->send($url, $data);
    }

    /**
     * Sends notice to Airbrake.
     *
     * It returns an associative array with 2 possible keys:
     * - ['id' => '12345'] - notice id on success.
     * - ['error' => 'error message'] - error message on failure.
     *
     * @param array $notice Airbrake notice
     */
    public function sendNotice($notice)
    {
        foreach ($this->filters as $filter) {
            $notice = $filter($notice);
            if ($notice === null || $notice === false) {
                // Ignore notice.
                return 0;
            }
        }

        $data = json_encode($notice);
        $resp = $this->postNotice($this->getNoticesURL(), $data);
        if ($resp['data'] === false) {
            return 0;
        }
        $res = json_decode($resp['data'], true);
        if ($res == null) {
            return ['error' => $resp['data']];
        }
        return $res;
    }

    /**
     * Notifies Airbrake about exception.
     *
     * Under the hood notify is a shortcut for buildNotice and sendNotice.
     *
     * @param \Throwable|\Exception $exc Exception or class that implements similar interface.
     */
    public function notify($exc)
    {
        $notice = $this->buildNotice($exc);

        return $this->sendNotice($notice);
    }

    /**
     * Builds notices URL
     *
     * @return string
     */
    protected function getNoticesURL()
    {
        if (!empty($this->noticesUrl)) {
            return $this->noticesUrl;
        }

        $schemeAndHost = $this->opt['host'];

        if (!preg_match('~^https?://~i', $schemeAndHost)) {
            $schemeAndHost = "https://$schemeAndHost";
        }

        return $this->noticesUrl = sprintf(
            '%s/api/v3/projects/%d/notices?key=%s',
            $schemeAndHost,
            $this->opt['projectId'],
            $this->opt['projectKey']
        );
    }
    
    protected function getEnvironment()
    {
        return array(
            "PHP_SELF" => $_SERVER["PHP_SELF"],
            "argv" => $_SERVER["argv"],
            "argc" => $_SERVER["argc"],
            "GATEWAY_INTERFACE" => $_SERVER["GATEWAY_INTERFACE"],
            "SERVER_ADDR" => $_SERVER["SERVER_ADDR"],
            "SERVER_NAME" => $_SERVER["SERVER_NAME"],
            "SERVER_SOFTWARE" => $_SERVER["SERVER_SOFTWARE"],
            "SERVER_PROTOCOL" => $_SERVER["SERVER_PROTOCOL"],
            "REQUEST_METHOD" => $_SERVER["REQUEST_METHOD"],
            "REQUEST_TIME" => $_SERVER["REQUEST_TIME"],
            "REQUEST_TIME_FLOAT" => $_SERVER["REQUEST_TIME_FLOAT"],
            "QUERY_STRING" => $_SERVER["QUERY_STRING"],
            "DOCUMENT_ROOT" => $_SERVER["DOCUMENT_ROOT"],
            "HTTP_ACCEPT" => $_SERVER["HTTP_ACCEPT"],
            "HTTP_ACCEPT_CHARSET" => $_SERVER["HTTP_ACCEPT_CHARSET"],
            "HTTP_ACCEPT_ENCODING" => $_SERVER["HTTP_ACCEPT_ENCODING"],
            "HTTP_ACCEPT_LANGUAGE" => $_SERVER["HTTP_ACCEPT_LANGUAGE"],
            "HTTP_CONNECTION" => $_SERVER["HTTP_CONNECTION"],
            "HTTP_HOST" => $_SERVER["HTTP_HOST"],
            "HTTP_REFERER" => $_SERVER["HTTP_REFERER"],
            "HTTP_USER_AGENT" => $_SERVER["HTTP_USER_AGENT"],
            "HTTPS" => $_SERVER["HTTPS"],
            "REMOTE_ADDR" => $_SERVER["REMOTE_ADDR"],
            "REMOTE_HOST" => $_SERVER["REMOTE_HOST"],
            "REMOTE_PORT" => $_SERVER["REMOTE_PORT"],
            "REMOTE_USER" => $_SERVER["REMOTE_USER"],
            "REDIRECT_REMOTE_USER" => $_SERVER["REDIRECT_REMOTE_USER"],
            "SCRIPT_FILENAME" => $_SERVER["SCRIPT_FILENAME"],
            "SERVER_ADMIN" => $_SERVER["SERVER_ADMIN"],
            "SERVER_PORT" => $_SERVER["SERVER_PORT"],
            "SERVER_SIGNATURE" => $_SERVER["SERVER_SIGNATURE"],
            "PATH_TRANSLATED" => $_SERVER["PATH_TRANSLATED"],
            "SCRIPT_NAME" => $_SERVER["SCRIPT_NAME"],
            "REQUEST_URI" => $_SERVER["REQUEST_URI"],
            "PHP_AUTH_DIGEST" => $_SERVER["PHP_AUTH_DIGEST"],
            "PHP_AUTH_USER" => $_SERVER["PHP_AUTH_USER"],
            "PHP_AUTH_PW" => $_SERVER["PHP_AUTH_PW"],
            "AUTH_TYPE" => $_SERVER["AUTH_TYPE"],
            "PATH_INFO" => $_SERVER["PATH_INFO"],
            "ORIG_PATH_INFO" => $_SERVER["ORIG_PATH_INFO"]
        );
    }

    protected function rootDirectoryFilter($notice)
    {
        $projectRoot = $this->opt['rootDirectory'];
        $notice['context']['rootDirectory'] = $projectRoot;
        foreach ($notice['errors'] as &$error) {
            if (empty($error['backtrace'])) {
                continue;
            }
            foreach ($error['backtrace'] as &$frame) {
                $frame['file'] = preg_replace("~^$projectRoot~", '[PROJECT_ROOT]', $frame['file']);
            }
        }
        return $notice;
    }
}
