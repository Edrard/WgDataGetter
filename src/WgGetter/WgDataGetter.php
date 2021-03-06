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
        MyLog::changeType(array('warning','error','critical'),'wgdata_g');
        $this->curl = $curl;
    }
    public function debugLog(){       
        MyLog::changeType(array('debug','info','warning','error','critical'),'wgdata_g');
        MyLog::info("Debug Log on",array(),'wgdata_g'); 
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
            $start = microtime(true);
            $request = $this->getUrls($urls);
            if(!empty($end) && (microtime(true) - $start) < 1){
                $sleep = max(0,1100000 - (microtime(true) - $start)*1000000);
                MyLog::error("Need to sleep ".($sleep/1000000).' sec',array(),'wgdata_g');
                usleep($sleep);
            }
            MyLog::debug("Run Time ".(microtime(true) - $start),array(count($this->curl->getSessions())),'wgdata_g');
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
                    MyLog::error("Wrong Status Code in JSON for URL - ".$url,array($tmp['error']['message']),'wgdata_g');
                    sleep($this->sleep);
                    $result[$key] = $this->getUrls(array($key => $url))[$key];    
                }
            }while($tmp['status'] !='ok'); 
            $data[$key] = $tmp['data'];
        }
        return $data;
    }
}