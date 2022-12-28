<?php

    function processPair($symbol, $asset){
        global $_OPTIONS;
        
        $pair = $symbol.$asset;
        $tf = $_OPTIONS['scanner_timeframe'];        
        $sqDir = $_OPTIONS['scanner_direction'] === 'short' ? 'short' : 'long';
        $startPoint = $_OPTIONS['scanner_startpoint'];        
        $sqDelta = $_OPTIONS['scanner_sqdelta'];
        $sqDepth = $_OPTIONS['scanner_depth'];
                    
        if(isset($_OPTIONS['calc_filters']['months_limit']) && $_OPTIONS['calc_filters']['months_limit'] > 0){
            $fkl = getFirstCandleDate($pair, $_OPTIONS['use_futures']);
            $ml = intval($_OPTIONS['calc_filters']['months_limit']);
            echo $pair.": Checking pair start date, start limit: ".date('Y-m-d', strtotime('-'.$ml.' months')).".... ";
            if($fkl === false){echo "Error getting first candle\n"; return false;}else{echo "OK, first candle is: ".date('Y-m-d', $fkl/1000)."\n";}
            if(strtotime('-'.$ml.' months') < $fkl/1000){echo $pair.': First candle is too high for filter, skipping....'."\n\n"; return false;}
        }
        
        foreach($_OPTIONS['scanner_ranges'] as $rid => $r){echo $pair.': Range #'.$rid.' '.date('Y-m-d H:i:s', $r[0]/1000).' :: '.date('Y-m-d H:i:s', $r[1]/1000)."\n";}
        
        echo $pair.": Syncing ".$tf." klines for whole period....\n";
        $candles = getCandles($asset, $symbol, $tf, strtotime('2010-01-01')*1000, time()*1000);
        
        $cnt = count($candles);        
        if($cnt <= 0){echo $pair.": No candles found, skipping....\n\n"; return false;}        
        echo $pair.': Current klines range is '.date('Y-m-d H:i:s', $candles[0][0]/1000).' - '.date('Y-m-d H:i:s', ($candles[$cnt-1][6]+1)/1000).', '.$cnt." candles total\n";
        
        $acandles = [];        
        foreach($_OPTIONS['scanner_ranges'] as $rid => $range){
            $rc = []; foreach($candles as $c){if($c[0] >= $range[0] && $c[6] <= $range[1]){$rc[] = $c;}}
            $str = $pair.': Calculating action candles for R'.$rid.', total '.count($rc).' candles....';
            $ac = getActionCandles($rc, $sqDelta, $_OPTIONS['scanner_volume'], $sqDepth, $startPoint, $sqDir, $str);            
            echo "OK, found ".count($ac)." items\n";
            $acandles = array_merge($acandles, $ac);
        }

        if(isset($_OPTIONS['scanner_flags_list']) && count($_OPTIONS['scanner_flags_list']) > 1){
            echo $pair.': Filtering action candles by flags @ '.implode(',', $_OPTIONS['scanner_flags_tfs']).' :: '.$_OPTIONS['scanner_flags'].'....';
            $acnt = count($acandles); for($i=0;$i<$acnt;$i++){if(!checkActionFlag($_OPTIONS['scanner_flags_list'], $_OPTIONS['scanner_flags'], $acandles[$i])){unset($acandles[$i]);}}
            $acandles = array_values($acandles); echo "OK\n";
        }

        if($_OPTIONS['cmd'] === 'ACTION_CANDLES'){
            echo $pair.': Dumping action candles to file....';
            echo file_put_contents($_OPTIONS['out_file'], json_encode(['tf' => $tf, 'ac' => $acandles, 'cnt' => count($acandles)])."\n", FILE_APPEND) ? "OK\n\n" : "FAILED\n\n";
            return true;
        }

        echo $pair.': Calculating action candles....OK, total '.count($acandles)." items\n";
        $mt = isset($_OPTIONS['calc_filters']['min_take']) ? $_OPTIONS['calc_filters']['min_take'] : 0;
        if(count($acandles) <= 0 || ($mt > 0 && count($acandles)*$_OPTIONS['calc_take_max'] < $mt)){echo $pair.": Not enought action candles for current filter, skipping....\n\n"; return false;}
        //if(count($acandles) < 10 && $_OPTIONS['cmd'] !== 'BACKTEST_DATA'){echo $pair.": Too little action candles, skipping....\n\n"; return false;}
        if(count($acandles) > 10000){echo $pair.": Too much action candles, skipping....\n\n"; return false;}
        
        echo $pair.": Syncing 1m klines....\n";
        $candles = $tf === '1m' ? $candles : getCandles($asset, $symbol, '1m', strtotime('2010-01-01')*1000, time()*1000);
        
        $cnt = count($candles);
        if($cnt <= 0){echo $pair.": No 1m candles found, skipping....\n\n"; return false;}
        echo $pair.': Current klines range is '.date('Y-m-d H:i:s', $candles[0][0]/1000).' - '.date('Y-m-d H:i:s', ($candles[$cnt-1][6]+1)/1000)."\n";
        
        $acandles = fillActionCandles($pair, $acandles, $candles, $sqDepth);
        unset($candles);
        
        if($_OPTIONS['cmd'] === 'BACKTEST_DATA'){
            $trade_data = calcBacktestData($pair, $acandles, $_OPTIONS['calc_ep_start'], $_OPTIONS['calc_stop_start'], $_OPTIONS['calc_take_start'], $_OPTIONS['scanner_startpoint'], $_OPTIONS['scanner_direction'], $_OPTIONS['scanner_depth']);
        }else{
            echo $pair.": Calculating trade results....\r";
            $trade_data = calcTradeData($_OPTIONS['cmd'] === 'TRADE_DATA', $pair, $acandles, $_OPTIONS['calc_ep_start'], $_OPTIONS['calc_ep_max'], $_OPTIONS['calc_ep_step'], $_OPTIONS['calc_stop_start'], $_OPTIONS['calc_stop_max'], $_OPTIONS['calc_stop_step'], $_OPTIONS['calc_take_start'], $_OPTIONS['calc_take_max'], $_OPTIONS['calc_take_step'], $_OPTIONS['calc_filters'], $_OPTIONS['scanner_startpoint'], $_OPTIONS['scanner_direction'], $_OPTIONS['scanner_depth'], $_OPTIONS['calc_depth_reducer'], $_OPTIONS['pairs_risk_delta'], $_OPTIONS['pair_params']['tickSize'], $_OPTIONS['sort_order']);
            $trade_data = $trade_data === false || !is_array($trade_data) ? [] : $trade_data;
        }
        
        echo $pair.': Dumping results to file....';
        echo file_put_contents($_OPTIONS['out_file'], json_encode(['tf' => $tf, 'td' => $trade_data, 'cnt' => count($acandles)])."\n", FILE_APPEND) ? "OK\n\n" : "FAILED\n\n";    
    }

    function checkActionFlag($flags_list, $flags, $acandle){
        if($flags_list[0][0] > $acandle['ts']){return false;}
        if($flags_list[count($flags_list)-1][0] < $acandle['ts']){return $flags_list[count($flags_list)-1][1] === $flags;}
        
        $_f = false;
        foreach($flags_list as $fidx => $f){
            if($f[0] > $acandle['ts']){$_f = $flags_list[$fidx-1]; break;}
        }
        return $_f !== false && $_f[1] === $flags;
    }

    function calcBacktestData($pair, $data, $ep, $st, $tk, $sp, $direction, $depth){
        echo $pair.': Calculating backtest results....'."\n";
        
        $tr = [];
        $lastResultTS = 0;
        $cnt = ['s' => 0, 't' => 0];
        foreach($data as $c){
            if($c['ts'] < $lastResultTS){continue;}
            
            $_tr = calcTradeResult($c, $ep, $st, $tk, $direction, $depth, 0);
            if(in_array($_tr['result'], ['TAKE', 'STOP'])){
                $lastResultTS = $c['candles'][$_tr['idx']][6];

                $cnt[strtolower(substr($_tr['result'], 0, 1))]++;
                $epr = $direction === 'long' ? $c['enter_point'] - $c['enter_point'] / 100 * $ep : $c['enter_point'] + $c['enter_point'] / 100 * $ep;
                $rpr = $_tr['result'] === 'TAKE' ? floatval($_tr['take']) : floatval($_tr['stop']);
                
                $tr[] = [
                    'rpr' => $rpr,                          // result price
                    'epr' => $epr,                          // enter point price                    
                    'rsi' => $_tr['idx'],                   // result step index
                    'idx' => $c['idx'],                     // ep index in main tf
                    'res' => $_tr['result'],                // result
                    'ets' => $c['candles'][0][0],           // ep TS
                    'rts' => $c['candles'][$_tr['idx']][0], // result TS
                    'stp' => round($_tr['stop_perc'], 2),   // stop perc
                    'tkp' => round($_tr['take_perc'], 2),   // take perc
                ];
                
                echo $pair.': Result '.json_encode($tr[count($tr)-1]).date(' Y-m-d H:i:s', $c['candles'][$_tr['idx']][0]/1000)."\n";
                //echo print_r($_tr, 1);exit;
            }
        }
        
        echo $pair.': Total '.count($tr).' results, TAKES: '.$cnt['t'].', STOPS: '.$cnt['s']."\n";
        
        return $tr;
    }

    function calcTradeResult($data, $p_enter, $p_stop, $p_take, $direction, $depth, $tickSize){        
        $ep = $data['enter_point'];
        $enter_price = $direction === 'long' ? $ep - $ep / 100 * $p_enter                  : $ep + $ep / 100 * $p_enter;
        $stop_price  = $direction === 'long' ? $enter_price - $enter_price / 100 * $p_stop : $enter_price + $enter_price / 100 * $p_stop;
        $take_price  = $direction === 'long' ? $enter_price + $enter_price / 100 * $p_take : $enter_price - $enter_price / 100 * $p_take;
        
        $stop_ts = $data['candles'][0][0] + $data['period'] + ($data['period'] * $depth);

        $result = [
            'result' => '', 'p_enter' => $p_enter, 'p_stop' => $p_stop, 'p_take' => $p_take, 
            'take' => 0, 'take_perc' => 0, 'take_step' => 0, 
            'stop' => 0, 'stop_perc' => 0, 'stop_step' => 0,
        ];
        
        //$dbg = $p_enter == 5.1 && $p_stop == 1 && $p_take == 4.2 && $data['candles'][0][1] == 0.016262;
        //if($dbg){var_dump($data);exit;}
        
        $_price = 0;
        $start_idx = false;         
        //$enter_price = adjust_exchange_price($enter_price, floatval($tickSize));
        foreach($data['candles'] as $idx => $c){
            $_price = $_price === 0 ? ($direction === 'long' ? $c[3] : $c[4]) : ($direction === 'long' ? min($_price, $c[3]) : max($_price, $c[4]));
            if($c[0] > $data['candles'][0][0] + $data['period']){break;}
            if($direction === 'long' && $c[3] <= $enter_price){$start_idx = $idx; break;}
            if($direction === 'short' && $c[4] >= $enter_price){$start_idx = $idx; break;}
        }
        
        if($start_idx === false){
            $d = ($_price - $enter_price) / ($ep / 100);
            //echo "\n\n".'D:'.$d.' LP:'.$_price.' EPR:'.$enter_price.' EP:'.$ep."\n\n";exit;
            $result['result'] = 'START_IDX_1M_NOT_FOUND_'.round($d, 4);
            return $result;
        }
                
        $cnt = count($data['candles']);
        foreach($data['candles'] as $idx => $c){            
            if($idx < $start_idx){continue;}
            
            $isStopIdx = $c[0] >= $stop_ts;

            if($direction === 'long'){
                if(!$isStopIdx && $c[3] <= $stop_price){
                    $result['idx'] = $idx;
                    $result['result'] = 'STOP';                        
                    $result['stop_step'] = $idx;
                    $result['stop'] = $stop_price;
                    $result['stop_perc'] = $p_stop;
                    break;
                }
                
                if($idx === $start_idx && $c[4] >= $take_price){
                    $result['idx'] = $idx;                    
                    $result['result'] = 'TAKE';
                    $result['take_step'] = $idx;
                    $result['take'] = $take_price;
                    $result['take_perc'] = $p_take;
                    break;                    
                }
                
                if(!$isStopIdx && $idx > $start_idx && $c[2] >= $take_price){
                    $result['idx'] = $idx;
                    $result['result'] = 'TAKE';
                    $result['take_step'] = $idx;
                    $result['take'] = $take_price;
                    $result['take_perc'] = $p_take;
                    break;                    
                }
                
                if($idx >= $cnt-1 || $isStopIdx){
                    $close_price = $data['candles'][$idx-1][4];
                    $perc = abs($close_price- $enter_price) / ($enter_price / 100);
                    
                    if($c[1] >= $enter_price){
                        $result['idx'] = $idx-1;
                        $result['take'] = $close_price;
                        $result['take_step'] = $idx-1;
                        $result['result'] = 'TAKE';
                        $result['take_perc'] = $perc;
                    }else{
                        $result['idx'] = $idx-1;
                        $result['stop'] = $close_price;
                        $result['result'] = 'STOP';
                        $result['stop_step'] = $idx-1;
                        $result['stop_perc'] = $perc;
                    }
                    break;
                }
            }
            
            if($direction === 'short'){
                if($c[4] >= $stop_price){
                    $result['idx'] = $idx;
                    $result['result'] = 'STOP';                        
                    $result['stop_step'] = $idx;
                    $result['stop'] = $stop_price;
                    $result['stop_perc'] = $p_stop;
                    break;
                }
                
                if($idx === $start_idx && $c[4] <= $take_price){
                    $result['idx'] = $idx;
                    $result['take'] = $c[4];
                    $result['result'] = 'TAKE';
                    $result['take_step'] = $idx;
                    $result['take_perc'] = $p_take;
                    break;                    
                }
                
                if($idx > $start_idx && $c[3] <= $take_price){
                    $result['idx'] = $idx;
                    $result['take'] = $c[3];
                    $result['result'] = 'TAKE';
                    $result['take_step'] = $idx;
                    $result['take_perc'] = $p_take;
                    break;                    
                }
                
                //if($idx >= $cnt-1 || ($c[6] > ($data['candles'][0][0] + ($data['period'] * $depth)))){
                if($idx >= $cnt-1 || ($c[0] >= $stop_ts)){
                    $perc = abs($c[4] - $enter_price) / ($enter_price / 100);
                    
                    if($c[4] <= $enter_price){
                        $result['idx'] = $idx;
                        $result['take'] = $c[4];
                        $result['take_step'] = $idx;
                        $result['result'] = 'TAKE';
                        $result['take_perc'] = $perc;
                    }else{
                        $result['idx'] = $idx;
                        $result['stop'] = $c[4];
                        $result['result'] = 'STOP';
                        $result['stop_step'] = $idx;
                        $result['stop_perc'] = $perc;
                    }
                    break;
                }
            }
        }

        return $result;
    }
    
    function calcTradeData($full_results, $pair, $data, $ep_start, $ep_max, $ep_step, $stop_start, $stop_max, $stop_step, $take_start, $take_max, $take_step, $filter, $start_point, $direction, $depth, $use_depth, $pairs_risk_delta, $tickSize, $sort_order){
        $pi_enter = $ep_start;
        $si_enter = $stop_start;
        $ti_exit = $take_start;
        $di_current = $depth;
        
        //$top_ratio_idx = 0;
        //$top_ratio = 0;
        
        $top_result_idx = 0;
        $top_result = $sort_order === 'stop_perc' ? 88888888 : 0;
        
        $results = [];
        
        
        while($di_current > 1){
            while($pi_enter <= $ep_max){
                while($si_enter <= $stop_max){
                    while($ti_exit <= $take_max){
                        if(isset($filter['min_eptr']) && $pi_enter / $ti_exit < $filter['min_eptr']){$ti_exit += $take_step; continue;}
                        if(isset($filter['max_eptr']) && $pi_enter / $ti_exit > $filter['max_eptr']){$ti_exit += $take_step; continue;}
                        
                        echo $pair.': Calculating trade results.... D:'.$di_current.' E:'.$pi_enter.' S:'.$si_enter.' T:'.$ti_exit.str_pad('', 10)."\r";
                        
                        $calc_amount = ['am' => 100, 'min' => 100, 'max' => 100];
                        $take_cnt = $stop_cnt = $stop_perc = $take_perc = $lastResultTS = 0;
                        foreach($data as $c){
                            if($c['ts'] < $lastResultTS){continue;}

                            $tr = calcTradeResult($c, $pi_enter, $si_enter, $ti_exit, $direction, $di_current, $tickSize);
                            
                            if(isset($tr['idx'])){
                                $take_perc += $tr['take_perc']; 
                                $stop_perc += $tr['stop_perc'];

                                $take_cnt += $tr['result'] === 'TAKE' ? 1 : 0;
                                $stop_cnt += $tr['result'] === 'STOP' ? 1 : 0;                            
                                
                                $lastResultTS = $c['candles'][$tr['idx']][6];

                                if($tr['result'] === 'TAKE'){$calc_amount['am'] += $calc_amount['am'] / 100 * $tr['take_perc'];}
                                if($tr['result'] === 'STOP'){$calc_amount['am'] -= $calc_amount['am'] / 100 * $tr['stop_perc'];}
                                $calc_amount['min'] = min($calc_amount['min'], $calc_amount['am']);
                                $calc_amount['max'] = max($calc_amount['max'], $calc_amount['am']);
                            }
                        }
                        
                        $cti_exit = $ti_exit;
                        $ti_exit += $take_step;                        
                        
                        $risk2 = 0;
                        if($take_cnt > 0){
                            $_stop_perc = $stop_perc < 1 ? 1 : $stop_perc;
                            $_stop_cnt = $stop_cnt <= 0 ? 1 : $stop_cnt;
                            $risk2 = round(($take_perc / $take_cnt) / ($_stop_perc / $_stop_cnt), 4);
                        }
                        
                        $ratio = floatval(number_format($take_perc - ($stop_perc < 1 ? 1 : $stop_perc), 0, '.', ''));                    
                        $risk = floatval(number_format($take_perc / ($stop_perc <= 1 ? 1 : $stop_perc), 4, '.', ''));                    
                        $calc_coeff = round(($take_cnt + $stop_cnt) * $ratio * $risk * ($pi_enter / $ti_exit), 0);
                        
                        if(!checkTradeResultFilters($pair, $filter, $pairs_risk_delta, $ratio, $risk, $risk2, $stop_perc, $take_perc)){continue;}
                        
                        $calc_amount['am'] = round($calc_amount['am'], 2);
                        $calc_amount['min'] = round($calc_amount['min'], 2);
                        $calc_amount['max'] = round($calc_amount['max'], 2);

                        $sort_values = ['ratio' => $ratio, 'stop_perc' => $stop_perc, 'calc_coeff' => $calc_coeff, 'res_a' => $calc_amount['am']];
                        if(in_array($sort_order, ['stop_perc'])){
                            $top_result_idx = $sort_values[$sort_order] > $top_result ? $top_result_idx : count($results); 
                            $top_result = min($top_result, $sort_values[$sort_order]);
                        }else{
                            $top_result_idx = $sort_values[$sort_order] <= $top_result ? $top_result_idx : count($results); 
                            $top_result = max($top_result, $sort_values[$sort_order]);
                        }
                        
                        //$top_ratio_idx = $ratio <= $top_ratio ? $top_ratio_idx : count($results);
                        //$top_ratio = max($top_ratio, $ratio);



                        $results[] = [
                            'risk' => $risk,
                            'risk2' => $risk2,
                            'ratio' => $ratio,
                            'depth' => $di_current,
                            'take_cnt' => $take_cnt,
                            'stop_cnt' => $stop_cnt,
                            'calc_coeff' => $calc_coeff,
                            'calc_amount' => $calc_amount,
                            'p_enter' => floatval(number_format($pi_enter, 2)),
                            's_enter' => floatval(number_format($si_enter, 2)),
                            'min_take' => floatval(number_format($cti_exit, 2)),
                            'take_perc' => floatval(number_format($take_perc, 2, '.', '')),
                            'stop_perc' => floatval(number_format($stop_perc, 2, '.', ''))
                            //'min_take' => $cti_exit,
                            //'max_take_perc' => floatval(number_format($max_take_perc, 2, '.', '')),
                        ];
                        
                        if($use_depth > 0){
                            //echo json_encode($results[count($results)-1])."\n";
                        }
                        
                        $sval = $sort_order !== 'res_a' ? $results[$top_result_idx][$sort_order] : $results[$top_result_idx]['calc_amount']['am'];
                        if($sval === $sort_values[$sort_order]){
                            //echo $top_ratio_idx.': '.json_encode($results[$top_ratio_idx])."\n";
                            //echo (count($results)-1).': '.json_encode($results[count($results)-1])."\n\n";
                            if($results[$top_result_idx]['s_enter'] > $si_enter){$top_result_idx = count($results)-1;}// echo json_encode($results[$top_ratio_idx])."\n\n";}
                            if($results[$top_result_idx]['depth'] > $di_current){$top_result_idx = count($results)-1;}// echo json_encode($results[$top_ratio_idx])."\n\n";}
                            //if($results[$top_result_idx]['depth'] < $di_current){$top_result_idx = count($results)-1;}// echo json_encode($results[$top_ratio_idx])."\n\n";}
                        }
                        
                        /*if($results[$top_ratio_idx]['ratio'] === $ratio){
                            //echo $top_ratio_idx.': '.json_encode($results[$top_ratio_idx])."\n";
                            //echo (count($results)-1).': '.json_encode($results[count($results)-1])."\n\n";
                            if($results[$top_ratio_idx]['s_enter'] > $si_enter){$top_ratio_idx = count($results)-1;}// echo json_encode($results[$top_ratio_idx])."\n\n";}
                            if($results[$top_ratio_idx]['depth'] > $di_current){$top_ratio_idx = count($results)-1;}// echo json_encode($results[$top_ratio_idx])."\n\n";}
                        }*/
                    }
                    
                    $ti_exit = $take_start;
                    $si_enter += $stop_step;                    
                }
                
                $pi_enter += $ep_step;
                $si_enter = $stop_start;
            }
            
            $pi_enter = $ep_start;
            $ti_exit = $take_start;
            $si_enter = $stop_start;            
            $di_current = $use_depth > 0 ? $di_current - 1 : 0;
        }
        
        echo "\n".$pair.": Calculation finished, found ".count($results)." variants, sorted by ".$sort_order."\n";
        
        if(count($results) > 0){
            if($full_results){return $results;}
            
            $r = $results[$top_result_idx];
            echo $pair.': Top trade result #'.$top_result_idx.': '.json_encode($r)."\n";            
            
            /*echo $pair.': Sorting trade results....';
            usort($results, function($a, $b){return $b['ratio'] === $a['ratio'] ? 0 : ($b['ratio'] < $a['ratio'] ? -1 : 1);});
            echo "OK, sorted by ratio\n";            
            $r = $results[0];
            echo $pair.': Top trade result #'.$top_ratio_idx.': '.json_encode($r)."\n";*/
            
            return $r;
        }
        
        return false;
    }
    
    function checkTradeResultFilters($pair, $filter, $pairs_risk_delta, $ratio, $risk, $risk2, $stop_perc, $take_perc){
        if($ratio < 0){return false;}

        //echo json_encode($filter)."\n".implode(' ', [$pair, $ratio, $risk, $stop_perc, $take_perc])."\n";

        if(isset($filter['min_risk']) && isset($pairs_risk_delta[$pair])){
            if($risk < floatval($pairs_risk_delta[$pair]) + $filter['min_risk']){return false;}
        }

        if(isset($filter['min_ratio']) && $ratio < $filter['min_ratio']){return false;}
        if(isset($filter['max_ratio']) && $ratio > $filter['max_ratio']){return false;}                    
        
        if(isset($filter['min_risk']) && $risk < $filter['min_risk']){return false;}
        if(isset($filter['max_risk']) && $risk > $filter['max_risk']){return false;}
        
        if(isset($filter['min_risk2']) && $risk2 < $filter['min_risk2']){return false;}
        if(isset($filter['max_risk2']) && $risk2 > $filter['max_risk2']){return false;}
        
        if(isset($filter['min_stop']) && $stop_perc < $filter['min_stop']){return false;}
        if(isset($filter['max_stop']) && $stop_perc > $filter['max_stop']){return false;}
        
        if(isset($filter['min_take']) && $take_cnt * $cti_exit < $filter['min_take']){return false;}
        if(isset($filter['max_take']) && $take_cnt * $cti_exit > $filter['max_take']){return false;}
        
        return true;
    }
    
    function cleanTradeData($data){        
        $i = 0;
        $unset = [];
        $cnt = count($data);
        
        while($i < $cnt - 1){
            for($j=0;$j<$cnt;$j++){
                if($data[$i]['take_perc'] === $data[$j]['take_perc'] && $data[$i]['stop_perc'] === $data[$j]['stop_perc'] && 
                   $data[$i]['p_enter'] === $data[$j]['p_enter'] && $data[$i]['min_take'] === $data[$j]['min_take'] && 
                   $data[$i]['s_enter'] < $data[$j]['s_enter']){
                    $unset[$j] = 1;
                }
            }
            
            $i++;
        }
        
        if(count($unset) > 0){
            foreach($unset as $k => $v){unset($data[$k]);}
        }

        return $data;
    }

?>
