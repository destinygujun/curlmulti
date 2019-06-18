<?php 
namespace UXin\Curlmulti\Signer;

class AsIsSigner implements Signer
{
    public function sign(array $payload)
    {
        return $payload;
    }
}