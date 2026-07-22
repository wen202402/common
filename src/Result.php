<?php

namespace fater\common;

class Result{
    public $code=0;
    public $msg='success';
    public $data=[];
    public $timestamp;

    /**
     * @param int $code
     * @param $msg
     * @param $data
     * @param $timestamp
     */
    public function __construct(int $code, $msg, $data=[])
    {
        $this->code = $code;
        $this->msg = $msg;
        $this->data = $data;
        $this->timestamp = time();
    }


}