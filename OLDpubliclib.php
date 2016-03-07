<?php

    /**
     * Instantiates a provider
     * 
     * @return enrollment_provider
     */
    public static function create_provider() {
        $provider_class = self::provider_class();

        return ($provider_class) ? new $provider_class() : false;
    }

    /**
     * Loads currently selected UES enrollment provider and returns class name
     * 
     * @return string
     */
    public static function provider_class() {

        // get currently selected provider
        $provider_name = get_config('enrol_ues', 'enrollment_provider');

        if ( ! $provider_name) {
            return false;
        }

        // check if this provider is still installed as a plugin
        $plugins = self::listAvailableProviders();

        if ( ! isset($plugins[$provider_name])) {
            return false;
        }

        // require UES libraries
        self::requireLibs();

        global $CFG;
        
        $basedir = $CFG->dirroot . '/local/' . $provider_name;
        $provider_class_name = $provider_name . '_enrollment_provider';
        
        // load provider
        if (file_exists($basedir . '/plugin.php')) {
            require_once $basedir . '/plugin.php';
            $class = $provider_name . '_enrollment_plugin';
            $fn = 'ues_load_' . $provider_name . '_provider';
            $class::$fn();
        }

        return $provider_class_name;
    }