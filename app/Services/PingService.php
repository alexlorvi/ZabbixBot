<?php

namespace ZabbixBot\Services;

class PingService { 
    public function ping($host,$count,callable $callback) { 
        $command = 'ping -O -c '.$count.' ' . escapeshellarg($host); 
        $descriptorspec = [
            1 => ['pipe', 'w'], 
            2 => ['pipe', 'w'] 
        ];
        $process = proc_open($command, $descriptorspec, $pipes); 
        if (is_resource($process)) { 
            while ($line = fgets($pipes[1])) { 
                $callback($line);
            } 
            fclose($pipes[1]); 
            $return_value = proc_close($process); 

            if ($return_value !== 0) { 
                $callback("Ping command failed!");
            } 
        } 
    }
}