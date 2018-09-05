<?php
namespace edrard\WgGetter;

use edrard\Log\MyLog;
use edrard\Curl\Curl;

class WgDataGetter
{ 
    protected $curl = FALSE;
    protected $multi = 10;
    protected $urls = array();
    protected $sleep = 5;
    function __construct(Curl $curl,$multi = 10)
    {  
        MyLog::init('logs','wgdata_g');
        MyLog::changeType(array('warning','error','critical'));
        $this->curl = $curl;
    }
    public function setMultiVar($multi){
        $this->multi = (int) $multi;
    }
    public function getMultiVar(){
        return $this->multi;
    }
    public function setUrls($array){
        $this->urls = array_special_merge_samere($this->urls,$array);
    }
    public function cleanUrls(){
        $this->urls = array();
    }
    public function getData( Closure $function = NULL, $instead = FALSE ){
        $end = array();
        foreach(array_chunk($this->urls,$this->multi,TRUE) as $urls){
            $request = $this->getUrls($urls);
            $request = $function !== NULL ? $function($request,$urls) : $request;
            if($instead === FALSE){
                $request = $this->check($request,$urls);
            }  
            $end = array_special_merge($end,$request);  
        }
        $this->cleanUrls();
        return $end;
    }
    private function getUrls($urls){
        $this->curl->setCurlRetry(TRUE);
        $this->curl->setSleep(function($retry,$url){
            $sleep = max(1,101-$retry);
            MyLog::error("Retry count:".($sleep).' Url - '.$url,array(),'wgdata_g');
            return $sleep;
        });
        foreach($urls as $key => $link){
            $this->curl->addSession( $link, $key );
        }
        $ret = $this->curl->exec();
        $this->curl->close();
        return $ret;
    }
    private function check($result,$url_info)
    {
        $data = array();
        foreach($url_info as $key => $url){
            do{
                $tmp = json_decode($result[$key],true); 
                if(!isset($tmp['status']) || (isset($tmp['status']) && $tmp['status'] != 'ok')){
                    MyLog::error("Wrong Status Code in JSON for URL - ".$url,array(),'wgdata_g');
                    sleep($this->sleep);
                    $result[$key] = $this->getUrls(array($key => $url))[$key];    
                }
            }while($tmp['status'] !='ok'); 
            $data[$key] = $tmp['data'];
        }
        return $data;
    }
}