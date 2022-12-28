<?php

    function initCurlHandle($close = false){
        global $_CH, $_LIMITS;
        
    
        if(isset($_LIMITS['usedWeight'])){
            if($_LIMITS['usedWeight'] > 400){sleep(2);}
            if($_LIMITS['usedWeight'] > 600){sleep(4);}
            if($_LIMITS['usedWeight'] > 800){sleep(8);}
            if($_LIMITS['usedWeight'] > 1000){
                echo 'ERROR: too much API usedWeight: '.$_LIMITS['usedWeight']; sleep(rand(60, 120));
            }
        }
        
        if(isset($_LIMITS['usedWeight1m'])){
            if($_LIMITS['usedWeight1m'] > 400){sleep(2);}
            if($_LIMITS['usedWeight1m'] > 600){sleep(4);}
            if($_LIMITS['usedWeight1m'] > 800){sleep(8);}
            if($_LIMITS['usedWeight1m'] > 1000){
                echo 'ERROR: too much API usedWeight-1m: '.$_LIMITS['usedWeight1m']; sleep(rand(60, 120));
            }
        }
		        
        if(!$_CH){
            $_CH = curl_init();
            curl_setopt($_CH, CURLOPT_TIMEOUT, 4);
            curl_setopt($_CH, CURLOPT_MAXREDIRS, 4);
            curl_setopt($_CH, CURLOPT_HEADER, true);            
            curl_setopt($_CH, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($_CH, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($_CH, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($_CH, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($_CH, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($_CH, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($_CH, CURLOPT_HTTPHEADER, ['Connection: Keep-Alive', 'Keep-Alive: 300']);
            curl_setopt($_CH, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; ru; rv:1.9.2.13) Gecko/20101203 Firefox/3.6.13');
        }
        
        if($close){curl_close($_CH); $_CH = false;}        
        return $_CH;
    }


    function getRemoteData($url){
        global $_LIMITS, $_WORK_DIR;
        
        $errCounter = 0;        
        while($errCounter < 4){
            $ch = initCurlHandle();
            curl_setopt($ch, CURLOPT_URL, $url);            
            //echo '['.$errCounter.'] Getting data from '.$url.'.... ';
            
            try{
                $res = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if(curl_errno($ch) !== 0){$res = false; echo 'ERROR: #'.curl_errno($ch).' '.curl_error($ch).' ';}
                if(intval($code) !== 200){echo 'ERROR: Code: '.$code.', Data: '.$res." "; $res = false;}
            } catch (Exception $e) {
                echo 'ERROR: EXCEPTION '.var_dump($e)." ";
                $res = false;
            }
            
            if($res !== false){
                $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $header = substr($res, 0, $hs);
                $res = substr($res, $hs);
                
                $header = explode("\n", $header);
                foreach($header as $h){
                    if(strpos($h, 'x-mbx-used-weight:') !== false)   {$_LIMITS['usedWeight']   = intval(trim(substr($h, strpos($h, ':')+2)));}
                    if(strpos($h, 'x-mbx-used-weight-1m:') !== false){$_LIMITS['usedWeight1m'] = intval(trim(substr($h, strpos($h, ':')+2)));}
                    if(strpos($h, 'retry-after:') !== false)	     {$_LIMITS['retryAfter']   = intval(trim(substr($h, strpos($h, ':')+2)));}
                }
                
                $dfile = $_WORK_DIR.'inet.log';
                $edfile = $_WORK_DIR.'inet.error.log';
                if(file_exists($dfile.'.sw')){                
                    $sl = [];
                    if(isset($_LIMITS['usedWeight']))  {$sl[] = 'UW: '.$_LIMITS['usedWeight'];}
                    if(isset($_LIMITS['usedWeight1m'])){$sl[] = 'UW1M: '.$_LIMITS['usedWeight1m'];}
                    if(isset($_LIMITS['retryAfter']))  {$sl[] = 'RA: '.$_LIMITS['retryAfter'];}
                    if(count($sl) <= 0){$sl[] = 'NO_RW_DATA';}
                    $str = date('Y-m-d H:i:s.').str_pad(gettimeofday()["usec"], 6, '0');
                    //$str .= ' #'.$CONFIG['GROUP']['guID'].'_'.$CONFIG['GID'].', '.implode(', ', $sl);
                    $str .= ' '.implode(', ', $sl);
                    $str .= ' :: GET '.str_replace(['https://www.binance.com', 'https://api.binance.com'], '', $url).', BODY_SIZE: '.strlen($res);
                    if(isset(json_decode($res, true)['code'])){
                        file_put_contents($edfile, $str.' :: '.$res."\n", FILE_APPEND);
                    }else{
                        file_put_contents($dfile, $str."\n", FILE_APPEND);
                    }
                }
                
                break;
            }
            
            initCurlHandle(true);
            $errCounter++;
            sleep(4);
        }
        
        return $res === false ? '' : $res;
    }

?>
