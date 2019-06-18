<?php
/**
 * Created by PhpStorm.
 * User: gujun3
 * Date: 2019/6/17
 * Time: 14:59
 */

namespace UXin\Curlmulti;

use Illuminate\Support\Facades\Facade;

class CurlMultiFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'curlmulti';
    }
}