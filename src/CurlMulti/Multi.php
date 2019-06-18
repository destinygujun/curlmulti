<?php 
namespace UXin\CurlMulti\CurlMulti;

abstract class Multi
{
    protected $api = [];
    protected $apis = [];

//    abstract public function res();

    abstract public function ok();

    abstract public function code();

    abstract public function message();

    abstract public function data();
}
