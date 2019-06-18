<?php
/**
 * Created by PhpStorm.
 * User: gujun3
 * Date: 2019/6/17
 * Time: 14:58
 */

namespace UXin\Curlmulti;

use Illuminate\Support\Arr;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use UXin\CurlMulti\Signer\AsIsSigner;
use UXin\CurlMulti\CurlMulti\MultiResponse;
use UXin\CurlMulti\CurlMulti\MultiException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;

/**
 * Request api according to api configs and return a fetched result
 */
class CurlMulti
{
    private static $config = [];
    private static $client = null;
    private $configs = [];
    private $defaults = [
        'method'        => 'GET',
        'codeField'     => 'code',
        'messageField'  => 'message',
        'dataField'     => 'data',
        'successCode'   => 1,
        'timeout'       => 0,
    ];
    private $api = [];
    private $apis = [];
    const TIMEOUT = 10;
    const MULTI_TIMEOUT = 6;
    public static $multiStartTimestamp = 0;
    public static $multiRequest = false;//只有开启multi http请求时候才会true，不然只会返回url，不会发起请求

    /**
     * CurlMulti constructor.
     * @param Client $client
     */
    public function __construct(Client $client, array $config)
    {
        if (is_null(self::$client)) {
            self::$client = new Client();
        }
        self::$config = $config;
    }

    /**
     * 批量获取接口数据
     * @param array $requestList 请求数组
     * [
     *  urlkey => [
     *           method => '',
     *           key => '', 配置key
     *           url => '',
     *          'options' => 和guzzle options一致
     *      ]
     * ]
     * @param string $url 默认请求url
     * @param string $method 默认请求方式
     * @param array $options 一般是guzzle系统参数
     * @param bool $errorContinue
     *
     * @return array|mixed
     * @throws \Throwable
     */
    public static function multi($requestList, $url = '', $method = 'GET', $options = [], $errorContinue = false)
    {
        $requestTime = date('Y-m-d H:i:s');
        $returnData = [];
        $data = self::baseRequestMulti($method, $url, $options, $requestList, $errorContinue);

        foreach ($data['data'] as $key => $value) {
            $returnData[$key] = json_decode($value['res'], true);
            $returnData['time'] = $value['time'];
        }

        $res = [
            'code' => 1,
            'msg' => '',
            'data' => $returnData
        ];

        // 有错误
        if ($data['code'] == 0) {
            if ($errorContinue == false) {
                $requestList = self::formatMultiRequestList($method, $url, $options = [], $requestList);

                //格式化日志
                $log = [];
                foreach ($data['error_class_list'] as $key => $errorClass) {
                    $item = $requestList[$key];
                    array_push($log, [
                        'url' => $item['url'],
                        'request_time' => $requestTime,
                        'options' => $item['options'],
                        'msg' => $errorClass->__toString()
                    ]);
                }

                // 如果不是错误继续
                if ($errorContinue == false) {
                    $res = [
                        'code' => 0,
                        'msg' => json_encode($log),
                        'data' => []
                    ];
                }

            }
        }

        return $res;
    }

    /**
     * 批量获取接口数据
     * @param $method 默认请求方式
     * @param string $url 默认请求url
     * @param array $options 一般是guzzle系统参数 一维数组 会和$requestList的options 进行 array_merge($options, $item['options']);
     * @param array $requestList 请求数组
     * [
     *  1 => [
     *           method => '',
     *           url => ''
     *          'options' => 和guzzle options一致
     *      ]
     * ]
     * @param bool $errorContinue
     * @return array
     * @throws \Throwable
     */
    public static function baseRequestMulti($method, $url = '', $options = [], $requestList, $errorContinue = false)
    {
        $requestTime = microtime(true);
        // 返回数据
        $return = [
            'code' => 1,
            'data' => [],
            'error_class_list' => []
        ];

        // 如果请求数据为空
        if (empty($requestList)) {
            return $return;
        }

        // 载入客户端 GuzzleHttp\Client
        self::loadClient();

        //返回真正的url数据
        $returnData = [];
        // 请求url报错的所有业务类
        $errorClassList = [];
        try {
            // 最终的接口类数组
            $requestAsyncList = [];
            $requestList = self::formatMultiRequestList($method, $url, $options, $requestList);

            foreach ($requestList as $key => $item) {
                $item = self::formatOptions($item);
                $request = self::$client->requestAsync(
                    $item['method'],
                    $item['url'],
                    $item['options']
                )->then(
                    function (ResponseInterface $response) use ($key, $item, $requestTime, &$returnData) {
                        $endTimeStamp = microtime(true);
                        $returnData[$key]['res'] = $response->getBody()->getContents();
                        $returnData[$key]['time'] = $endTimeStamp - $requestTime;
                    },
                    //错误
                    function (RequestException $e) use ($key, $item, $requestTime, &$returnData, &$errorClassList, $errorContinue) {
                        $endTimeStamp = microtime(true);
                        $time = $endTimeStamp - $requestTime;
                        $errorClassList[$key]['res'] = $e;
                        $errorClassList[$key]['time'] = $time;
                        if ($errorContinue != false) {
                            $returnData[$key]['res'] = json_encode([]);
                            $returnData[$key]['time'] = $time;
                        }
                    });
                $requestAsyncList[$key] = $request;
            }
            self::$multiStartTimestamp = microtime(true);
            // 异步执行
            Promise\unwrap($requestAsyncList);
        } catch (\Exception $e) {
            $return['code'] = 0;
        }
        if (!empty($errorClassList)) {
            $return['code'] = 0;
        }
        $return['data'] = $returnData;
        $return['error_class_list'] = $errorClassList;
        return $return;
    }

    /**
     * 格式化批量请求数据的接口
     * @param $method 默认请求方式
     * @param string $url 默认请求url
     * @param array $options 一般是guzzle系统参数 一维数组 会和$requestList的options 进行 array_merge($options, $item['options']);
     * @param array $requestList 请求数组
     * [
     *  1 => [
     *           method => '',
     *           url => ''
     *          'options' => 和guzzle options一致
     *      ]
     * ]
     * @return array
     */
    private static function formatMultiRequestList($method, $url = '', $options = [], $requestList)
    {
        // 格式化默认参数
        $options['timeout'] = Arr::get($options, 'timeout', 10);
        $options['connect_timeout'] = Arr::get($options, 'connect_timeout', 10);
        $options['read_timeout'] = Arr::get($options, 'read_timeout', 10);

        $return = [];
        foreach ($requestList as $key => $item) {
            if (empty($item['options'])) {
                $item['options'] = $options;
            } else {
                $item['options'] = array_merge($options, $item['options']);
            }

            if (empty($item['method'])) {
                $item['method'] = $method;
            }

            if (empty($item['url'])) {
                $item['url'] = $url;
            }
            $return[$key] = $item;
        }

        return $return;
    }

    /**
     * 载入客户端
     */
    public static function loadClient()
    {
        if (is_null(self::$client)) {
            self::$client = new Client();
        }
    }

    /**
     * fetch one
     * @param array $configs
     * @param array $payload
     * @param array $options
     * @return MultiException|MultiResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function fetch(
        array $configs,
        array $payload = [],
        array $options = []
    ) {
        $this->configs = $configs;
        return $this->request($this->api(), $payload, $options);
    }

    /**
     * 默认处理
     * @return array
     * @throws \Exception
     */
    private function api()
    {
        if (empty($this->configs['url'])) {
            throw new \Exception("invalid api config: no url");
        }
        if (strpos($this->configs['url'], 'http') === false) {
            $this->configs['url'] = 'http://'.$this->configs['url'];
        }

        return array_merge($this->defaults, $this->configs);
    }

    /**
     * one request
     * @param array $api
     * @param array $params
     * @param array $options
     * @return MultiException|MultiResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function request(array $api, array $params, array $options)
    {
        try {
            $api = [
                'api' => $api,
                'params' => $params,
                'options' => $options,
            ];
            $options = self::formatOptions($api);
            $response = $this->client->request($api['method'], $api['url'], $options);
            return new MultiResponse($api, $response);
        } catch (\Exception $e) {
            return new MultiException($api, $e);
        }
    }

    /**
     * @param array $item
     * @return array
     * @throws \Exception
     */
    private static function formatOptions(array &$item)
    {
        if (!empty(Arr::get($item, 'key', ''))) {
            $key = $item['key'];
            $api = self::$config[$key];
            $api = [
                'method' => isset($api['method']) ? $api['method'] : 'GET',
                'url' => $api['entry'],
                'codeFieldName' => Arr::get($api, 'codeFieldName', 'code'),
                'successCode' => Arr::get($api, 'successCode', 1),
                'messageFieldName' => Arr::get($api, 'messageFieldName', 'message'),
                'dataFieldName' => Arr::get($api, 'dataFieldName', 'data'),
                'timeout' => self::MULTI_TIMEOUT,
            ];
        } elseif (!empty(Arr::get($item, 'api', []))) {
            $api = Arr::get($item, 'api', []);
        }
        $method = Arr::get($api, 'method', 'GET');

        // sign relation
        $signed = Arr::get($item, 'params', []);
        if (!empty(Arr::get($item, 'params', []))) {
            $signed = self::signerFor($api)->sign($item['params']);
        }

        $options = Arr::get($item, 'options', []);
        $option = ($method == 'GET') ? ['query' => $signed] : ['form_params' => $signed];

        $parsed = parse_url($api['url']);
        $cookies = Arr::get($options, 'cookies', []);
        $headers = Arr::get($options, 'headers', []);
        $option['cookies'] = CookieJar::fromArray($cookies, $parsed['host']);
        $option['connect_timeout'] = $api['timeout'];
        $option['headers'] = $headers;

        return $option;
    }

    /**
     * 并发处理
     * @param array $api
     * @return AsIsSigner
     * @throws \Exception
     */
    private static function signerFor(array $api)
    {
        if (empty($api['signer'])) {
            return new AsIsSigner();
        }
        if (! class_exists($api['signer'])) {
            throw new \Exception("can not find signer class: {$api['signer']}");
        }
        return new $api['signer'];
    }
}