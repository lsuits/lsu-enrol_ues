<?php

class ConfigBase {
    
    private $config;
    
    public function getConfigs(){
        return $this->config;
    }
    
    public function setConfigs(){
        foreach($this->config as $conf){
            set_config(implode(',',$conf));
        }
    }
}
?>
