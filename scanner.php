<?php

    ini_set('memory_limit','16G');
    ini_set("display_errors", true);
    error_reporting(E_ALL ^ E_DEPRECATED);
    date_default_timezone_set('Europe/Moscow');
    

    $_WORK_DIR = dirname(__FILE__).'/';

    require_once($_WORK_DIR.'modules/inet.php');
    require_once($_WORK_DIR.'modules/sync.php');
    require_once($_WORK_DIR.'modules/options.php');
    require_once($_WORK_DIR.'modules/candles.php');
    require_once($_WORK_DIR.'modules/functions.php');
    require_once($_WORK_DIR.'modules/calculator.php');
    

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    
    //php scanner.php -f scan_params.conf symbol out_file
    
    sleep(2);
    $_CH = false;
    $_LIMITS = [];
    $_SORT = ['ratio', 'stop_perc', 'calc_coeff', 'res_a'];
    $_BLACKLIST = $_WORK_DIR.'blacklist.txt';
    $_WHITELIST = $_WORK_DIR.'whitelist.txt';
    $_DATA_DIR = $_WORK_DIR.'../scanner/DATA/';    
    $_STARTPOINTS = ['open', 'high', 'low', 'close'];
    $_TIMEFRAMES = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d', '3d', '1w', '1M'];
    
    checkSync($argv);
    $_OPTIONS = getOptions($argv);
    if($_OPTIONS['use_futures'] > 0){$_DATA_DIR = $_WORK_DIR.'../scanner/FDATA/';}
    
    foreach($_OPTIONS['scanner_pairs'] as $pidx => $p){
        $pair = $p.$_OPTIONS['scanner_asset'];
        echo $pair.': #'.($pidx+1).' of '.count($_OPTIONS['scanner_pairs']).' on '.($_OPTIONS['use_futures'] <= 0 ? 'SPOT' : 'FUTURES')." MARKET\n";        
        //if($_OPTIONS['cmd'] === false && checkBlackList($pair, $_OPTIONS['black_list'])){echo $pair.": Pair is blacklisted, skipping....\n\n"; sleep(1); continue;}
        if($_OPTIONS['cmd'] === false && !checkBlackList($pair, $_OPTIONS['white_list'], true)){echo $pair.": Pair is not in white list, skipping....\n\n"; sleep(1); continue;}
        
        $_OPTIONS['pair_params']['tickSize'] = 0;
        //$_OPTIONS['pair_params'] = getPairParams($pair);
        //if(!isset($_OPTIONS['pair_params']['tickSize'])){continue;}
        
        
        processPair($p, $_OPTIONS['scanner_asset']);
        sleep(4);
    }

?>
