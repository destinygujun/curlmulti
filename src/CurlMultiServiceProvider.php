<?php 
namespace UXin\CurlMulti;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class CurlMultiServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('curlmulti', function ($app) {
            $config = config('apiEntry');
            return new CurlMulti(app(Client::class), $config);
        });
    }
}