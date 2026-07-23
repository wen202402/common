<?php

namespace wen202402\common\chain;

use IEXBase\TronAPI\Provider\HttpProvider;
use IEXBase\TronAPI\Tron;


class TronClient{
    private Tron $tron;
    public  $usdtContractAddress = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    public function __construct(string $fullNode = 'https://api.trongrid.io', string $solidityNode = 'https://api.trongrid.io', string $eventServer = 'https://api.trongrid.io') {
        $full = new HttpProvider($fullNode);
        $solidity = new HttpProvider($solidityNode);
        $event = new HttpProvider($eventServer);

        $this->tron = new Tron($full, $solidity, $event);
    }

    /**
     * 设置发送方地址和私钥
     */
    public function setCredentials(string $fromAddress, string $privateKey): void{
        $this->tron->setAddress($fromAddress);
        $this->tron->setPrivateKey($privateKey);
    }

                                                            //获取 TRX 余额
    public function getTrxBalance(): mixed{

        return $this->tron->getBalance(null, true);    // null, true 保持你原来的参数
    }

                                                                                                              //发送 TRX
    public function sendTrx(string $toAddress, float|int $amount): mixed{
        return $this->tron->send($toAddress, $amount);
    }

                                                                                                                 //获取最新区块
    public function getLatestBlocks(int $count = 2): mixed{
        return $this->tron->getLatestBlocks($count);
    }

                                                                                                             //取USDT余额
    public function getUsdtRawBalance($fromAddress, $usdtContractAddress=''): mixed{
        return  $this->getContract($usdtContractAddress)->balanceOf($fromAddress);
    }




    public function getUsdtContractAddress(): string{
        return $this->usdtContractAddress;
    }

    public function setUsdtContractAddress(string $usdtContractAddress): void{
        $this->usdtContractAddress = $usdtContractAddress;
    }



                                                                                                                     //转帐usdt
    public function transfer(  string $to, string $amount): array{
        return $this->getContract()->transfer($to, $amount);
    }




    public function getContract($usdtContractAddress=''){
        if (empty($usdtContractAddress))$usdtContractAddress=$this->usdtContractAddress;

        return  $this->tron->contract($usdtContractAddress);
    }


                                                                                          //如果你还要支持 createAccount/changeAccountName，可在这里继续封装
    public function getTronInstance(): Tron{
        return $this->tron;
    }
}
