<?php
namespace QafooLabs\Profiler;
/**
 * QafooLabs Profiler
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */


/**
 * Low-level abstraction for storage of profiling data.
 */
interface Backend
{
    public function storeProfile(array $data);
    public function storeMeasurement(array $data);
    public function storeError(array $data);
}


class CurlBackend implements Backend
{
    private $certificationFile;
    private $connectionTimeout;
    private $timeout;

    public function __construct($certificationFile = null, $connectionTimeout = 1, $timeout = 1)
    {
        $this->certificationFile = $certificationFile;
        $this->connectionTimeout = $connectionTimeout;
        $this->timeout = $timeout;
    }

    public function storeProfile(array $data)
    {
        if (function_exists("curl_init") === false) {
            return;
        }

        $data['server'] = gethostname();

        $this->request("https://profiler.qafoolabs.com/api/profile/create", $data);
    }

    public function storeMeasurement(array $data)
    {
        $this->request("https://profiler.qafoolabs.com/api/performance", array(
            'server' => gethostname(),
            'time' => time(),
            'apps' => array(
                $data['apiKey'] => array(
                    'operations' => array(
                        $data['op'] => array(
                            'lastRev' => time()+84600,
                            'cnt' => 1,
                            'err' => 0,
                            'wt' => array(
                                array(
                                    'wt' => $data['wt'],
                                    'mem' => $data['mem'],
                                    'c' => $data['c'],
                                )
                            )
                        )
                    )
                )
            )
        ));
    }

    public function storeError(array $data)
    {
    }

    private function request($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectionTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($this->certificationFile) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CAINFO, $this->certificationFile);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "User-Agent: QafooLabs Profiler Collector DevMode"
        ));

        curl_exec($ch);
    }
}
/**
 * QafooLabs Profiler
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */


class NetworkBackend implements Backend
{
    const TYPE_PROFILE = 'profile';
    const TYPE_ERROR = 'error';

    public function storeProfile(array $data)
    {
        $this->storeThroughFileSocket(self::TYPE_PROFILE, $data);
    }

    private function storeThroughFileSocket($dataType, array $data)
    {
        $fp = @stream_socket_client("unix:///tmp/qprofd.sock");

        if ($fp == false) {
            return;
        }

        stream_set_timeout($fp, 0, 10000); // 10 milliseconds max
        @fwrite($fp, json_encode(array('type' => $dataType, 'payload' => $data)));
        fclose($fp);
    }

    public function storeMeasurement(array $data)
    {
        $fp = @stream_socket_client("udp://127.0.0.1:8135");

        if ($fp == false) {
            return;
        }

        stream_set_timeout($fp, 0, 200);
        @fwrite($fp, json_encode($data, JSON_FORCE_OBJECT));
        fclose($fp);
    }

    public function storeError(array $data)
    {
        $this->storeThroughFileSocket(self::TYPE_ERROR, $data);
    }
}
/**
 * Qafoo Profiler Client
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */


/**
 * Anonymize SQL statements.
 *
 * To avoid passing private data to the profiler services you have to anonymize
 * SQL statements that are collected as custom timers.
 *
 * The class detects all strings and numbers in sql statements and replaces
 * them with question marks (?).
 *
 * If your library such as Doctrine DBAL only uses prepared statements anyways
 * and you don't put user input into SQL yourself, then you can skip this step.
 *
 * @TODO: Rename to something more meaningful, since it also anonymizes messages.
 */
class SqlAnonymizer
{
    /**
     * @var string
     */
    const SPLIT_NUMBERS_AND_QUOTED_STRINGS = '(("[^"]+"|\'[^\']+\'|([0-9]*\.)?[0-9]+))';

    /**
     * Anonymize SQL string.
     *
     * Splits a SQL at quoted literals and numbers, replacing them with
     * question marks for anonymization.
     *
     * @param string $sql
     * @return string
     */
    static public function anonymize($sql)
    {
        return preg_replace(self::SPLIT_NUMBERS_AND_QUOTED_STRINGS, '?', $sql);
    }
}
/**
 * QafooLabs Profiler
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace QafooLabs;

// Guard against PECL extension
if (class_exists('QafooLabs\Profiler')) {
    return;
}

/**
 * QafooLabs Profiler PHP API
 *
 * Contains all methods to gather measurements and profile data with
 * Xhprof and send to local Profiler Collector Daemon.
 *
 * This class is intentionally monolithic and static to allow
 * users to easily copy it into their projects or auto-prepend PHP
 * scripts.
 *
 * @example
 *
 *      QafooLabs\Profiler::start($apiKey);
 *      QafooLabs\Profiler::setTransactionName("my tx name");
 *
 * Calling the {@link stop()} method is not necessary as it is
 * called automatically from a shutdown handler, if you are timing
 * worker processes however it is necessary:
 *
 *      QafooLabs\Profiler::stop();
 *
 * The method {@link setTransactionName} is required, failing to call
 * it will result in discarding of the data. You can automatically
 * guess a name using the following snippet:
 *
 *      QafooLabs\Profiler::useRequestAsTransactionName();
 *
 * If you want to collect custom timing data you can use for SQL:
 *
 *      $sql = "SELECT 1";
 *      $id = QafooLabs\Profiler::startSqlCustomTimer($sql);
 *      mysql_query($sql);
 *      QafooLabs\Profiler::stopCustomTimer($id);
 *
 * Or for any other timing data:
 *
 *      $id = QafooLabs\Profiler::startCustomTimer('solr', 'q=foo');
 *      QafooLabs\Profiler::stopCustomTimer($id);
 */
class Profiler
{
    const TYPE_WEB = 1;
    const TYPE_WORKER = 2;

    private static $apiKey;
    private static $started = false;
    private static $shutdownRegistered = false;
    private static $operationName;
    private static $customVars;
    private static $customTimers;
    private static $customTimerCount = 0;
    private static $operationType;
    private static $error;
    private static $profiling = false;
    private static $sampling = false;
    private static $correlationId;
    private static $backend;
    private static $callIds;
    private static $uid;

    public static function setBackend(Profiler\Backend $backend)
    {
        self::$backend = $backend;
    }

    /**
     * Start profiling in development mode.
     *
     * This will always generate a full profile and send it to the profiler via cURL.
     */
    public static function startDevelopment($apiKey, $flags = 0, array $options = array())
    {
        self::setBackend(new Profiler\CurlBackend());
        self::start($apiKey, 100, $flags, $options);
    }

    /**
     * Start production profiling for the application.
     *
     * There are three modes for profiling:
     *
     * 1. Wall-time only profiling of the complete request (no overhead)
     * 2. Full profile/trace using xhprof (depending of # function calls
     *    significant overhead)
     * 3. Whitelist-profiling mode only interesting functions.
     *    (5-40% overhead, requires custom xhprof version >= 0.95)
     *
     * Decisions to profile are made based on a sample-rate and random picks.
     * You can influence the sample rate and pick a value that suites your
     * application. Applications with lower request rates need a much higher
     * transaction rate (25-50%) than applications with high load (<= 1%).
     *
     * Factors that influence sample rate:
     *
     * 1. Second parameter $sampleRate to start() method.
     * 2. _qprofiler Query Parameter equals md5 hash of API-Key sets sample rate to 100%
     * 3. X-QPTreshold and X-QPHash HTTP Headers. The hash is a sha256 hmac of the treshold with the API-Key.
     * 4. QAFOO_PROFILER_TRESHOLD environment variable.
     *
     * start() automatically invokes a register shutdown handler that stops and
     * transmits the profiling data to the local daemon for further processing.
     *
     * @param string            $apiKey Application key can be found in "Settings" tab of Profiler UI
     * @param int               $sampleRate Sample rate in full percent (1= 1%, 20 = 20%). Defaults to every fifth request
     * @param int               $flags XHProf option flags.
     * @param array             $options XHProf options.
     *
     * @return void
     */
    public static function start($apiKey, $sampleRate = 20, $flags = 0, array $options = array())
    {
        if (self::$started) {
            return;
        }

        if (strlen($apiKey) === 0) {
            return;
        }

        $config = self::loadConfig($apiKey);

        if (isset($config['general']['enabled']) && !$config['general']['enabled']) {
            return;
        }

        $sampleRate = isset($config['general']['sample_rate']) ? $config['general']['sample_rate'] : $sampleRate;
        $flags = isset($config['general']['xhprof_flags']) ? $config['general']['xhprof_flags'] : $flags;

        self::init(php_sapi_name() == "cli" ? self::TYPE_WORKER : self::TYPE_WEB, $apiKey);

        if (function_exists("xhprof_enable") == false) {
            return;
        }

        self::$profiling = self::decideProfiling($sampleRate);

        if (self::$profiling == true) {
            xhprof_enable($flags, $options); // full profiling mode
            return;
        }

        // careless hack to do this with a custom version, need to fork own 'xhprof' extension soon.
        if (version_compare(phpversion('xhprof'), '0.9.5') < 0) {
            return;
        }

        if (isset($config['calls'])) {
            xhprof_enable(0, array('functions' => array_values($config['calls'])));
            self::$sampling = true;
            self::$callIds = $config['calls'];
        }
    }

    private static function loadConfig($apiKey)
    {
        $config = array();
        if (strpos($apiKey, '..') === false && file_exists('/etc/qafooprofiler/' . $apiKey . '.ini')) {
            $config = parse_ini_file('/etc/qafooprofiler/' . $apiKey . '.ini', true);
        }

        if (isset($config['general']['backend']) && $config['general']['backend'] === 'curl') {
            self::setBackend(new Profiler\CurlBackend());
        }

        return $config;
    }

    private static function decideProfiling($treshold)
    {
        if (isset($_GET["_qprofiler"]) && $_GET["_qprofiler"] === md5(self::$apiKey)) {
            return true;
        }

        if (isset($_SERVER["HTTP_X_QPTRESHOLD"]) && isset($_SERVER["HTTP_X_QPHASH"])) {
            if (hash_hmac("sha256", $_SERVER["HTTP_X_QPTRESHOLD"], self::$apiKey) === $_SERVER["HTTP_X_QPHASH"]) {
                $treshold = intval($_SERVER["HTTP_X_QPTRESHOLD"]);

                if (isset($_SERVER["HTTP_X_QPCORRELATIONID"])) {
                    self::$correlationId = strval($_SERVER["HTTP_X_QPCORRELATIONID"]);
                }
            }
        }
        if (isset($_SERVER["QAFOO_PROFILER_TRESHOLD"])) {
            $treshold = intval($_SERVER["QAFOO_PROFILER_TRESHOLD"]);
        }

        $rand = rand(1, 100);

        return $rand <= $treshold;
    }

    /**
     * Ignore this transaction and don't collect profiling or performance measurements.
     *
     * @return void
     */
    public static function ignoreTransaction()
    {
        if (self::$started && (self::$profiling || self::$sampling)) {
            self::$profiling = false;
            self::$sampling = false;

            xhprof_disable();
        }

        self::$started = false;
    }

    private static function init($type, $apiKey)
    {
        if (self::$shutdownRegistered == false) {
            register_shutdown_function(array("QafooLabs\\Profiler", "shutdown"));
            self::$shutdownRegistered = true;
        }

        if (self::$backend === null) {
            self::$backend = new Profiler\NetworkBackend();
        }

        self::$profiling = false;
        self::$sampling = false;
        self::$apiKey = $apiKey;
        self::$customVars = array();
        self::$customTimers = array();
        self::$customTimerCount = 0;
        self::$operationName = 'default';
        self::$error = false;
        self::$operationType = $type;
        self::$started = microtime(true);
        self::$callIds = null;
        self::$uid = null;
    }

    /**
     * Return transaction hash for real user monitoring.
     *
     * @return string
     */
    public static function getTransactionHash()
    {
        return substr(sha1(self::$operationName), 0, 12);
    }

    /**
     * Return api hash used for real user monitoring.
     *
     * @return string
     */
    public static function getApiHash()
    {
        return sha1(self::$apiKey);
    }

    public static function setCorrelationId($id)
    {
        self::$correlationId = $id;
    }

    public static function setTransactionName($name)
    {
        self::$operationName = !empty($name) ? $name : 'empty';
    }

    /**
     * Use {@link setTransactionName} instead.
     *
     * @deprecated
     */
    public static function setOperationName($name)
    {
        self::$operationName = $name;
    }

    /**
     * Start a custom timer for SQL execution with the give SQL query.
     *
     * Returns the timer id to be passed to {@link stopCustomTimer()}
     * for completing the timing process. Queries passed to this
     * method are anonymized using {@link SqlAnonymizer::anonymize()}.
     *
     * @param string $query
     * @return integer|bool
     */
    public static function startSqlCustomTimer($query)
    {
        return self::startCustomTimer('sql', Profiler\SqlAnonymizer::anonymize($query));
    }

    /**
     * Start a custom timer for the given group and using the given description.
     *
     * Data passed as description it not anonymized. It is your responsibility to
     * strip the data of any input that might cause private data to be sent to
     * Qafoo Profiler service.
     *
     * @param string $group
     * @param string $description
     * @return integer|bool
     */
    public static function startCustomTimer($group, $description)
    {
        if (self::$started == false || self::$profiling == false) {
            return false;
        }

        self::$customTimers[self::$customTimerCount] = array("s" => microtime(true), "id" => $description, "group" => $group);
        self::$customTimerCount++;

        return self::$customTimerCount - 1;
    }

    /**
     * Stop the custom timer with given id.
     *
     * @return bool
     */
    public static function stopCustomTimer($id)
    {
        if ($id === false || !isset(self::$customTimers[$id]) || isset(self::$customTimers[$id]["wt"])) {
            return false;
        }

        self::$customTimers[$id]["wt"] = intval(round((microtime(true) - self::$customTimers[$id]["s"]) * 1000000));
        unset(self::$customTimers[$id]["s"]);
        return true;
    }

    /**
     * Return all current custom timers.
     *
     * @return array
     */
    public static function getCustomTimers()
    {
        return self::$customTimers;
    }

    public static function isStarted()
    {
        return self::$started !== false;
    }

    public static function isProfiling()
    {
        return self::$profiling;
    }

    /**
     * Add a custom variable to this profile.
     *
     * Examples are the Request URL, UserId, Correlation Ids and more.
     *
     * Please do *NOT* set private data in custom variables as this
     * data is not encrypted on our servers.
     *
     * Only accepts scalar values.
     *
     * The key 'url' is a magic value and should contain the request
     * url if you want to transmit it. The Profiler UI will specially
     * display it.
     *
     * @param string $name
     * @param scalar $value
     * @return void
     */
    public static function setCustomVariable($name, $value)
    {
        if (!self::$profiling || !is_scalar($value)) {
            return;
        }

        self::$customVars[$name] = $value;
    }

    public static function getCustomVariable($name)
    {
        return isset(self::$customVars[$name])
            ? self::$customVars[$name]
            : null;
    }

    /**
     * Stop all profiling actions and submit collected data.
     */
    public static function stop()
    {
        if (self::$started == false) {
            return;
        }

        $data = null;
        $sampling = self::$sampling;

        if (self::$profiling || self::$sampling) {
            $data = xhprof_disable();
        }
        $duration = intval(round((microtime(true) - self::$started) * 1000));

        self::$started = false;
        self::$profiling = false;
        self::$sampling = false;

        if (self::$error) {
            self::storeError(self::$operationName, self::$error, $duration);
            return;
        }

        if (!self::$operationName) {
            return;
        }

        if (!$sampling && $data) {
            self::storeProfile(self::$operationName, $data, self::$customTimers, self::$operationType);
        } else {
            $callData = array();

            if ($sampling) {
                // TODO: Can we force Xhprof extension to do this directly?
                $parsedData = array();

                foreach ($data as $parentChild => $childData) {
                    if ($parentChild === 'main()') {
                        continue;
                    }

                    list ($parent, $child) = explode('==>', $parentChild);

                    if (!isset($parsedData[$child])) {
                        $parsedData[$child] = array('wt' => 0, 'ct' => 0);
                    }
                    $parsedData[$child]['wt'] += $childData['wt'];
                    $parsedData[$child]['ct'] += $childData['ct'];
                }

                foreach (self::$callIds as $callId => $fn) {
                    if (isset($parsedData[$fn])) {
                        $callData["c$callId"] = $parsedData[$fn];
                    }
                }

                $duration = intval(round($data['main()']['wt'] / 1000));
            }

            self::storeMeasurement(self::$operationName, $duration, self::$operationType, $callData);
        }
    }

    private static function storeError($operationName, $errorData, $duration)
    {
        self::$backend->storeError(
            array(
                "op" => ($operationName === null ? '__unknown__' : $operationName),
                "error" => $errorData,
                "apiKey" => self::$apiKey,
                "wt" => $duration,
                "cid" => (string)self::$correlationId
            )
        );
    }

    private static function storeProfile($operationName, $data, $customTimers, $operationType)
    {
        if (!isset($data["main()"]["wt"]) || !$data["main()"]["wt"]) {
            return;
        }
        self::$backend->storeProfile(array(
            "uid" => self::getProfileTraceUuid(),
            "op" => $operationName,
            "data" => $data,
            "custom" => $customTimers,
            "vars" => self::$customVars ?: null,
            "apiKey" => self::$apiKey,
            "ot" => $operationType,
            "mem" => round(memory_get_peak_usage() / 1024),
            "cid" => (string)self::$correlationId,
        ));
    }

    private static function storeMeasurement($operationName, $duration, $operationType, array $callData)
    {
        self::$backend->storeMeasurement(array(
            "op" => $operationName,
            "ot" => $operationType,
            "wt" => $duration,
            "mem" => round(memory_get_peak_usage() / 1024),
            "apiKey" => self::$apiKey,
            "c" => $callData
        ));
    }

    /**
     * Use Request or Script information for the transaction name.
     *
     * @return void
     */
    public static function useRequestAsTransactionName()
    {
        self::setTransactionName(self::guessOperationName());
    }

    /**
     * Use {@link useRequestAsTransactionName()} instead.
     *
     * @deprecated
     */
    public static function guessOperationName()
    {
        if (php_sapi_name() === "cli") {
            return basename($_SERVER["argv"][0]);
        }

        $uri = strpos($_SERVER["REQUEST_URI"], "?")
            ? substr($_SERVER["REQUEST_URI"], 0, strpos($_SERVER["REQUEST_URI"], "?"))
            : $_SERVER["REQUEST_URI"];

        return $_SERVER["REQUEST_METHOD"] . " " . $uri;
    }

    public static function logFatal($message, $file, $line, $type = null)
    {
        if (self::$error) {
            return;
        }

        if ($type === null) {
            $type = E_USER_ERROR;
        }

        $message = Profiler\SqlAnonymizer::anonymize($message);

        self::$error = array("message" => $message, "file" => $file, "line" => $line, "type" => $type);
    }

    public static function shutdown()
    {
        $lastError = error_get_last();

        if ($lastError && ($lastError["type"] === E_ERROR || $lastError["type"] === E_PARSE || $lastError["type"] === E_COMPILE_ERROR)) {
            self::logFatal($lastError["message"], $lastError["file"], $lastError["line"], $lastError["type"]);
        }

        if (function_exists("http_response_code") && http_response_code() >= 500) {
            self::logFatal("PHP request set error HTTP response code to '" . http_response_code() . "'.", "", 0, E_USER_ERROR);
        }

        self::stop();
    }

    /**
     * Get a unique identifier for the current profile trace.
     *
     * Base64 encoded version of the binary representation of a UUID.
     *
     * @return string
     */
    public static function getProfileTraceUuid()
    {
        if (self::$uid === null) {
            $uuid = base64_encode(
                pack(
                    "h*",
                    sprintf(
                        '%04x%04x%04x%04x%04x%04x%04x%04x',
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff)
                    )
                )
            );
            $uuid = str_replace("/", "_", $uuid);
            $uuid = str_replace("+", "-", $uuid);

            self::$uid = substr($uuid, 0, strlen($uuid) - 2);
        }

        return self::$uid;
    }
}
