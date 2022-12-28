<?php

    function getPairs($asset, $useFutures){
        echo "Dumping pairs from exchange....";
        $url = $useFutures <= 0 ? 'https://api.binance.com/api/v3/exchangeInfo' : 'https://fapi.binance.com/fapi/v1/exchangeInfo';
        $data = json_decode(getRemoteData($url), true);
        if(!$data || count($data) <= 0 || count($data['symbols']) <= 0){echo "FAILED\n"; return false;}else{echo "OK\n";}
        
        $pairs = [];
        foreach($data['symbols'] as $s){
            if($s['status'] === 'TRADING' && $s['quoteAsset'] === $asset){
                $pairs[] = $s['baseAsset'];
            }
        }

        return $pairs;
    }

    function parseCandle($c){
        return [intval($c[0]), floatval($c[1]), floatval($c[2]), floatval($c[3]), floatval($c[4]), floatval($c[5]), intval($c[6]), floatval($c[7])];
    }

    function getFirstCandleDate($pair, $useFutures){
        $res = 0;
        $cfile = '/var/www/scanner/tmp/'.$pair.'.fcts'.($useFutures <= 0 ? '' : 'f');
        if(file_exists($cfile) && intval(file_get_contents($cfile)) > 0){
            return intval(file_get_contents($cfile));
        }

        $params['limit'] = 1;
        $params['symbol'] = $pair;
        $params['interval'] = '1d';
        $params['startTime'] = strtotime('2000-01-01') * 1000;
        $url = $useFutures <= 0 ? 'https://api.binance.com/api/v3/klines?' : 'https://fapi.binance.com/fapi/v1/klines?';
        
        $data = json_decode(getRemoteData($url.http_build_query($params)), true);
        //return !$data || count($data) <= 0 || !isset($data[0][0]) || intval($data[0][0]) <= 0 ? false : $data[0][0];

        $res = !$data || count($data) <= 0 || !isset($data[0][0]) || intval($data[0][0]) <= 0 ? 0 : $data[0][0];

        @mkdir(dirname($cfile), 0777, true);
        if(is_dir(dirname($cfile))){@file_put_contents($cfile, $res);}

        return $res;
    }

    function downloadCandles($pair, $tf, $start, $end, $useFutures){
        global $_LIMITS;
        
        $params['symbol'] = $pair;
        $params['interval'] = $tf;
        $params['limit'] = 1000;
        $params['startTime'] = $start;
        //$params['endTime'] = 0;
        $url = $useFutures <= 0 ? 'https://api.binance.com/api/v3/klines?' : 'https://fapi.binance.com/fapi/v1/klines?';
                
        $result = [];
        $lastCandleCTS = 0;
        
        while(true){
            //echo $pair.': Downloading klines: '.$tf.' from '.date('Y-m-d H:i:s', $params['startTime']/1000).'....';

            $data = json_decode(getRemoteData($url.http_build_query($params)), true);
            if(!$data || count($data) <= 0){/*echo "NO DATA\n";*/ break;}else{/*echo "OK, UW1M: ".$_LIMITS['usedWeight1m']."\n";*/}
            
            foreach($data as $c){if($end > 0 && $c[0] >= $end){$end = true; break;}else{$result[] = parseCandle($c); $lastCandleCTS = intval($c[6]);}}
            
            if($lastCandleCTS > time()*1000 || $end === true){break;}
            
            $params['startTime'] = $data[count($data)-1][6]+1;
            usleep(100000);
        }
        
        return $result;
    }
    
    function getCandles($base_asset, $quote_asset, $tf, $start, $finish, $update_storage_data = true){
        global $_OPTIONS, $_DATA_DIR;

        $_RAM_DIR = '/dev/shm/s1m/'.($_OPTIONS['use_futures'] <= 0 ? '' : 'FDATA/');
        
        $candles = [];
        $updated = false;
        
        $cfile = $_DATA_DIR.$base_asset.'/'.$tf.'/'.$quote_asset.'.json';
        $rfile = $_RAM_DIR.$base_asset.'/'.$tf.'/'.$quote_asset.'.json';

        $umask = umask(0);
        @mkdir(dirname($cfile), 0777, true);
        @mkdir(dirname($rfile), 0777, true);

        $lockfile = $cfile.'.lock';        
        $time = time(); while(file_exists($lockfile) && $time + 60 > time()){sleep(1);}
        
        if(file_exists($rfile)){
            //echo $quote_asset.$base_asset.': Loading candles from ram file....';
            $candles = json_decode(file_get_contents($rfile), true); //echo "OK\n";
        }
        
        if((!$candles || count($candles) <= 0) && file_exists($cfile)){
            $ecnt = 0;
            //echo $quote_asset.$base_asset.': Loading candles from regular file....';
            while($ecnt < 4){
                if(file_exists($cfile) && filesize($cfile) > 0){$candles = json_decode(file_get_contents($cfile), true);}
                $candles = !$candles || count($candles) <= 0 ? [] : $candles;            
                if(!is_array($candles) || count($candles) <= 0){$ecnt++; sleep(4);}else{break;}
            } //echo "OK\n";
        }        
        
        if(count($candles) <= 0){
            //echo $quote_asset.$base_asset.': No klines found, downloading all....'."\n";
            $candles = downloadCandles($quote_asset.$base_asset, $tf, $start, 0, $_OPTIONS['use_futures']);
            if(count($candles) > 0){$updated = true;}
        }else{       
            //echo $quote_asset.$base_asset.': Current klines range is '.date('Y-m-d H:i:s', $candles[0][0]/1000).' - '.date('Y-m-d H:i:s', ($candles[count($candles)-1][6]+1)/1000)."\n";
            /*if($candles[0][0] > $start){
                $nc = downloadCandles($quote_asset.$base_asset, $tf, $start, $candles[0][0], $_OPTIONS['use_futures']);
                if(count($nc) > 0){imitateMerge($nc, $candles); $candles = $nc; $updated = true;}
            }*/
            
            $lts = (floatval($candles[count($candles)-1][6])+1)/1000;
            if(time() > $lts + 5*60){
                $nc = downloadCandles($quote_asset.$base_asset, $tf, $candles[count($candles)-1][6]+1, 0, $_OPTIONS['use_futures']);
                if(count($nc) > 1){imitateMerge($candles, $nc); $updated = true;}
            }
        }

        $cnt = count($candles);
        if($updated && $cnt > 0){
            $lcts = floatval($candles[$cnt-1][6]);
            if(time()*1000 < $lcts){unset($candles[$cnt-1]); $cnt--;}
        }
        
        if($update_storage_data && $updated){
            $time = time(); while(file_exists($lockfile) && $time + 60 > time()){sleep(1);}
            
            @file_put_contents($lockfile, 1);
            //echo $quote_asset.$base_asset.': Updating candles data file....';
            file_put_contents($cfile, json_encode($candles)); //echo "OK\n";
            //echo $quote_asset.$base_asset.': Updating candles ram data file....';
            @file_put_contents($rfile, json_encode($candles)); //echo "OK\n";
            @unlink($lockfile);
        }

        if(!file_exists($rfile) || filesize($rfile) !== filesize($cfile)){
            echo $quote_asset.$base_asset.': Putting candles to ram data file....';
            @file_put_contents($rfile, json_encode($candles)); echo "OK\n";
        }
        
        $res = [];
        for($i=0;$i<$cnt;$i++){
            if($candles[$i][0] < $start){continue;}
            if($candles[$i][6] > $finish){break;}                
            $res[] = $candles[$i];
        }
        
        unset($candles);
        umask($umask);
        return $res;
    }

    function calcCandlesVolume($candles, $startIdx, $tsLimit){
        $v = 0;        
        for($i=$startIdx-1;$i>=0;$i--){            
            if(!isset($candles[$i])){break;}            
            if($candles[$startIdx][0] - $candles[$i][0] > $tsLimit){break;}

            if(isset($candles[$i][7])){
                $v += $candles[$i][7];
            }else{
                $v += $candles[$i][5] * (($candles[$i][2] + $candles[$i][3]) / 2);
            }
        }

        return $v;
    }

    function getActionCandles($candles, $delta = 0, $volume = 0, $depth = 0, $start_point = 'low', $direction = 'long', $str = ''){
        $result = [];
        $price_idx = ['open' => 1, 'high' => 2, 'low' => 3, 'close' => 4];
        $price_idx = $price_idx[$start_point];
        $delta_idx = $direction === 'long' ? 3 : 2;
        $cnt = count($candles);
        
        echo $str;
        if($cnt <= 0){return $result;}
        
        for($i=0;$i<$cnt;$i++){
            if(!isset($candles[$i+1])){break;}
            
            //if($i%1000 === 0){echo "\r".$str.$i;}
            
            $current_price = $candles[$i][$price_idx];
            $next_price = $candles[$i+1][$delta_idx];
            
            $current_delta = calc_perc_diff($current_price, $next_price, $direction);
            
            if($current_delta < $delta){continue;}            
            //if($volume > 0 && $candles[$i+1][5] < $volume){continue;}
            if($volume > 0 && calcCandlesVolume($candles, $i+1, 24*60*60*1000) < $volume){continue;}
            
            $result[] = [
                'idx' => $i,
                'candles' => [],
                'ts' => $candles[$i+1][0],
                'delta' => round($current_delta, 2),
                'enter_point' => floatval(myfnf($current_price)),
                'period' => $candles[$i][6] - $candles[$i][0] + 1,
            ];
            
            //echo json_encode($result[count($result)-1])."\n";
        }

        echo "\r".$str;
        return $result;
    }
    
    function fillActionCandles($pair, $acandles, $candles, $depth){
        $start_idx = 0;
        $acnt = count($acandles); $ccnt = count($candles);
        $str = $pair.': Filling actions candles by 1m.... ';
        echo $str.$acnt.' left';
        
        foreach($acandles as $ac_idx => $ac){
            $start_ts = $ac['ts'];
            $end_ts = $start_ts + $ac['period'] + ($ac['period'] * $depth);
                       
            for($i=$start_idx;$i<$ccnt;$i++){
                if($candles[$i][0] < $start_ts){continue;}
                if($candles[$i][6] > $end_ts){break;}                
                
                if(count($acandles[$ac_idx]['candles']) <= 0){$start_idx = $i;}                
                $acandles[$ac_idx]['candles'][] = $candles[$i];
            }
            
            echo "\r".$str.($acnt - $ac_idx).' left';
        }
        
        echo "\r".$str."OK, ".$acnt." filled\n";
        return $acandles;
    }


    function imitateMerge(&$a1, &$a2){foreach($a2 as $i){$a1[] = $i;}}
    function calc_perc_diff($cp, $np, $dir){
        return $dir === 'long' ? ($cp-$np) / ($cp / 100) : ($np-$cp) / ($cp / 100);
    }
    
?>
