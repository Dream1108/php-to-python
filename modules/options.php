<?php

    function getRanges($ranges){
        $res = [];
        if(count($ranges) > 0){
            foreach($ranges as $r){
                if(strlen($r[0]) && $r[0][0] === '*' && intval(substr($r[0], 1))){
                    $s = strtotime('-'.intval(substr($r[0], 1)).' days');
                }else{
                    $s = $r[0] === '*' ? strtotime('2010-01-01') : strtotime($r[0]); 
                }
                $e = $r[1] === '*' ? time() : strtotime($r[1]); 
                if(!$s || !$e || $s >= $e){continue;}
                $res[] = [$s*1000, $e*1000];
            }
        }
        
        return $res;
    }

    function getOptions($argv){
        global $_WORK_DIR, $_BLACKLIST, $_WHITELIST, $_TIMEFRAMES, $_STARTPOINTS, $_SORT;
        
        $cmd = isset($argv[4]) ? $argv[4] : false;
        $pair = isset($argv[2]) ? $argv[2] : false; if(!$pair){exit("ERROR: Pair is not defined\n\n");}        
        $out_file = isset($argv[3]) ? $argv[3] : false; if(!$out_file){exit("ERROR: Out file not defined\n\n");}
        $file = isset($argv[1]) ? $argv[1] : false; if(!$file || !file_exists($file)){exit("ERROR: Configuration file not found\n\n");}
        $params = json_decode(file_get_contents($file), true); if(!$params || count($params) <= 0){exit("ERROR: No data in configuration file\n\n");}
        
        if(!isset($params['scanner_timeframe']) || !in_array($params['scanner_timeframe'], $_TIMEFRAMES)){exit("ERROR: Wrong timeframe\n\n");}
        if(!isset($params['scanner_startpoint']) || !in_array($params['scanner_startpoint'], $_STARTPOINTS)){exit("ERROR: Wrong start point\n\n");}
       
        $params['scanner_ranges'] = !isset($params['scanner_ranges']) ? [] : getRanges($params['scanner_ranges']);
        if(count($params['scanner_ranges']) <= 0){exit("ERROR: Wrong date ranges\n\n");}
        
        $params['cmd'] = $cmd;
        $params['calc_filters'] = [];
        $params['out_file'] = $out_file;
        $params['scanner_pairs'] = [$pair];
        $params['calc_depth_reducer'] = isset($params['calc_depth_reducer']) ? intval($params['calc_depth_reducer']) : 0;
        $params['use_futures'] = isset($params['use_futures']) && intval($params['use_futures']) > 0 ? 1 : 0;
        $params['sort_order'] = !isset($params['sort_order']) || !in_array($params['sort_order'], $_SORT) ? 'ratio' : $params['sort_order'];
        
        $params['black_list'] = file_exists($_BLACKLIST) && filesize($_BLACKLIST) > 0 ? explode(',', file_get_contents($_BLACKLIST)) : [];
        $params['white_list'] = file_exists($_WHITELIST) && filesize($_WHITELIST) > 0 ? explode(',', file_get_contents($_WHITELIST)) : [];
        
        $params['pairs_risk_delta'] = isset($params['pairs_risk_delta']) && !empty($params['pairs_risk_delta']) ? explode(',', $params['pairs_risk_delta']) : [];
        $prd = []; foreach($params['pairs_risk_delta'] as $p){if(empty($p) || count(explode('|', $p)) !== 2){continue;}else{$p = explode('|', $p); $prd[$p[0]] = $p[1];}}
        $params['pairs_risk_delta'] = $prd;

        $params['scanner_flags'] = isset($params['scanner_flags']) && !empty($params['scanner_flags']) ? $params['scanner_flags'] : '';
        $params['scanner_flags_tfs'] = isset($params['scanner_flags_tfs']) && !empty($params['scanner_flags_tfs']) ? explode(',', $params['scanner_flags_tfs']) : [];
        if(strlen($params['scanner_flags']) > 0){
            if(count($params['scanner_flags_tfs']) !== strlen($params['scanner_flags'])){exit("ERROR: Wrong flags configuration\n\n");}
            $flagsFile = $_WORK_DIR.'tmp/'.strtoupper($params['scanner_asset'].'-'.$pair).'-'.implode('-', $params['scanner_flags_tfs']).'.json';
            if(!file_exists($flagsFile) || filesize($flagsFile) <= 0 || time() - filectime($flagsFile) > 1*60*60){exit("ERROR: Flags file not exists or too old\n\n");}
            
            $params['scanner_flags_list'] = json_decode(file_get_contents($flagsFile), true);
            if(!is_array($params['scanner_flags_list']) || count($params['scanner_flags_list']) <= 0){exit("ERROR: Bad data in flags file: ".$flagsFile."\n\n");}
        }

        if(isset($params['calc_filter_min_take']) && $params['calc_filter_min_take'] > 0){$params['calc_filters']['min_take'] = $params['calc_filter_min_take'];}
        if(isset($params['calc_filter_max_take']) && $params['calc_filter_max_take'] > 0){$params['calc_filters']['max_take'] = $params['calc_filter_max_take'];}
        if(isset($params['calc_filter_min_stop']) && $params['calc_filter_min_stop'] > 0){$params['calc_filters']['min_stop'] = $params['calc_filter_min_stop'];}
        if(isset($params['calc_filter_max_stop']) && $params['calc_filter_max_stop'] > 0){$params['calc_filters']['max_stop'] = $params['calc_filter_max_stop'];}
        if(isset($params['calc_filter_min_ratio']) && $params['calc_filter_min_ratio'] > 0){$params['calc_filters']['min_ratio'] = $params['calc_filter_min_ratio'];}
        if(isset($params['calc_filter_max_ratio']) && $params['calc_filter_max_ratio'] > 0){$params['calc_filters']['max_ratio'] = $params['calc_filter_max_ratio'];}
        if(isset($params['calc_filter_min_risk']) && $params['calc_filter_min_risk'] > 0){$params['calc_filters']['min_risk'] = $params['calc_filter_min_risk'];}
        if(isset($params['calc_filter_max_risk']) && $params['calc_filter_max_risk'] > 0){$params['calc_filters']['max_risk'] = $params['calc_filter_max_risk'];}
        if(isset($params['calc_filter_min_risk2']) && $params['calc_filter_min_risk2'] > 0){$params['calc_filters']['min_risk2'] = $params['calc_filter_min_risk2'];}
        if(isset($params['calc_filter_max_risk2']) && $params['calc_filter_max_risk2'] > 0){$params['calc_filters']['max_risk2'] = $params['calc_filter_max_risk2'];}
        if(isset($params['calc_filter_min_eptr']) && $params['calc_filter_min_eptr'] > 0){$params['calc_filters']['min_eptr'] = $params['calc_filter_min_eptr'];}
        if(isset($params['calc_filter_max_eptr']) && $params['calc_filter_max_eptr'] > 0){$params['calc_filters']['max_eptr'] = $params['calc_filter_max_eptr'];}
        if(isset($params['calc_filter_months_limit']) && $params['calc_filter_months_limit'] > 0){$params['calc_filters']['months_limit'] = $params['calc_filter_months_limit'];}
        
        return $params;
    }

    function checkBlackList($pair, $list, $whitelist = false){
        if(count($list) <= 0){return !$whitelist ? false : true;}        
        foreach($list as $v){            
            if(!empty($v) && strpos(trim(strtoupper($pair)), trim(strtoupper($v.'DOWN'))) === 0){return !$whitelist ? true : false;}
            if(!empty($v) && strpos(trim(strtoupper($pair)), trim(strtoupper($v.'UP'))) === 0){return !$whitelist ? true : false;}
            if(!empty($v) && strpos(trim(strtoupper($pair)), trim(strtoupper($v))) === 0){return true;}
        }        
        return false;
    }

?>
