<?php

namespace wen202402\common\helper;




class FileHelper extends \yii\helpers\FileHelper{


    public static function chmod755($dir){
      //  fwrite(STDOUT, sprintf("chmod755---------------%s----------start------------\n",$dir));


        $dir= \Yii::getAlias($dir);
        @mkdir( $dir, 0755, true);
      #  @chmod($dir, 0775);
    //    fwrite(STDOUT, sprintf("chmod755-------------------------nd------------\n"));
    }



    public static function findDirectoriesX($dir, $options = []){
        $options['recursive']=false;
        return  array_map('basename',  $dirs= static::findDirectories($dir, $options));
    }

    public static function removeDirectoryX($dir,$msg, $options = []){
         parent::removeDirectory($dir, $options);
         throw new \Exception($msg);

    }






}













