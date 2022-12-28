<?php

    // php -f scanner.php sync USDT 5m 0

    function checkSync($argv){
        global $_TIMEFRAMES, $_OPTIONS, $_WORK_DIR, $_DATA_DIR;
        
        if(!isset($argv[1]) || $argv[1] !== 'sync'){return;}
        
        $asset = isset($argv[2]) ? $argv[2] : ''; if(empty($asset)){exit("ERROR: Asset is not defined\n\n");}        
        $tf = isset($argv[3]) ? $argv[3] : ''; if(empty($tf) || !in_array($tf, $_TIMEFRAMES)){exit("ERROR: TF is not defined\n\n");}        
        $useFutures = isset($argv[4]) ? intval($argv[4]) : 0;
        
        echo 'Syncing all candles for '.$asset.' @ '.$tf.' on '.($useFutures <= 0 ? 'SPOT' : 'FUTURES')." market\n";
        $pairs = getPairs($asset, $useFutures);
        
        if(!$pairs || !is_array($pairs) || count($pairs) <= 0){exit("ERROR: pairs not found\n\n");}
        
        $_OPTIONS['use_futures'] = $useFutures;
        if($_OPTIONS['use_futures'] > 0){$_DATA_DIR = $_WORK_DIR.'../scanner/FDATA/';}
        
        syncAllCandles($asset, $tf, $pairs);
        
        exit("FINISHED\n\n");
    }

    function syncAllCandles($asset, $tf, $pairs){
        $cnt = count($pairs);
        
        //for($sidx = count($pairs)-1;$sidx >= 0;$sidx--){
            //$symbol = $pairs[$sidx];
        foreach($pairs as $sidx => $symbol){
            echo 'Syncing #'.($sidx+1).' of '.count($pairs).': '.$symbol."....\n";
            $c = getCandles($asset, $symbol, $tf, strtotime('2010-01-01')*1000, time()*1000, true);
            echo 'Syncing #'.($sidx+1).' of '.count($pairs).': '.$symbol." is FINISHED, total ".count($c)." candles\n\n";
        }
    }

?>
