<?php
namespace wen202402\common\helper;




class EnvHelper{


    static $loaded = false;

    const ENV_PREFIX = 'MYAPP_';

    private static $config = [];







    public static function get($name, $default = null) {
        $dir = \Yii::getAlias("@env") . DIRECTORY_SEPARATOR;
        if (!self::$loaded) {
            self::loadFile($dir . 'db.env');
            self::loadFile($dir . 'config.env');
            self::loadFile($dir . 'sms.env');
        }
        self::$loaded = true;
        $key = static::ENV_PREFIX . strtoupper(str_replace('.', '_', $name));
        if (!isset(static::$config[$key])) return $default;


        $result = static::$config[$key];
        if ('false' === $result) return false;
         elseif ('true' === $result) return true;

        return $result;
    }




    public static function loadFile($filePath) {
        if (!file_exists($filePath)) static::preExitMessage('no exist'.$filePath .  PHP_EOL );
        foreach (($env = parse_ini_file($filePath, true)) as $key => $val) {
            $prefix = static::ENV_PREFIX . strtoupper($key);
            if (!is_array($val)) {
                static::$config[$prefix] = $val;
                continue;
            }
            foreach ($val as $k => $v) static::$config[ $prefix . '_' . strtoupper($k)] = $v;

        }
    }





    public static function preExitMessage($message){

        return exit($message);
    }







    public static function getTimeZone(){
        return self::get('common.timeZone','Asia/Shanghai');
    }



    public static function getDbHost(){
        return    self::get('db.host');
    }




    public static function getDbPort(){
        return    self::get('db.port');
    }






    public static function getBakRoot(){
        return    self::get('db.root','root');
    }





    public static function getBakPassword(){
        return    self::get('db.rpwd','');
    }





    public static function getDbName(){
        return  self::get('db.dbname');
    }




    public static function getDbUsername(){
        return    self::get('db.username','');
    }




    public static function getDbPassword(){
        return    self::get('db.password','');
    }


}
