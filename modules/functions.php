<?php

    function myfnf($v, $dc = 8){
        return rtrim(rtrim(number_format($v, $dc, '.' , ''), '0'), '.');
    }

    function adjust_exchange_price($val, $tickSize){
        $ret = floatval(myfnf($val - bcmod($val, $tickSize, 16)));
        $ret = floatval(myfnf($ret - bcmod($ret, $tickSize, 8)));
        return $ret;
    }

    function getPairParams($pair){
        echo $pair.': Getting pair filters data....';
        $si = json_decode(getRemoteData('https://api.binance.com/api/v3/exchangeInfo'), true);
        if(!$si || !isset($si['symbols'])){echo "FAILED\n"; return false;}
        
        $res = [];
        
        foreach($si['symbols'] as $s){
            if($s['symbol'] === $pair){                
                foreach($s['filters'] as $f){
                    if($f['filterType'] === 'LOT_SIZE'){$res['minQty'] = $f['minQty'];}
                    if($f['filterType'] === 'LOT_SIZE'){$res['stepSize'] = $f['stepSize'];}
                    if($f['filterType'] === 'PRICE_FILTER'){$res['tickSize'] = $f['tickSize'];}
                    if($f['filterType'] === 'MIN_NOTIONAL'){$res['minNotional'] = $f['minNotional'];}
                }                
            }
        }
        
        echo 'OK, '.json_encode($res)."\n";
        return $res;
    }

    function memory_get_process_usage(){
        $status = file_get_contents('/proc/'.getmypid().'/status');
        $matchArr = []; preg_match_all('~^(VmRSS|VmSwap):\s*([0-9]+).*$~im', $status, $matchArr);        
        return !isset($matchArr[2][0]) || !isset($matchArr[2][1]) ? 0 : intval($matchArr[2][0]) + intval($matchArr[2][1]);
    }


    function getUsedMemory(){        
        return ['mgut' => memory_get_usage(true) / 1024 / 1024,
                'mguf' => memory_get_usage(false) / 1024 / 1024,       
                'mgput' => memory_get_peak_usage(true) / 1024 / 1024,
                'mgpuf' => memory_get_peak_usage(false) / 1024 / 1024,
                'system' => memory_get_process_usage() / 1024
        ];
    }

?>
