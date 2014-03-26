<?php
/**
 * @package enrol_ues
 */
interface enrollment_factory {
    // Returns a semester_processor
    function semester_source();

    // Returns a course_processor
    function course_source();

    // Returns a teacher_processor
    function teacher_source();

    // Retunrs a student_processor
    function student_source();

    // Returns teacher enrollment information for a given department
    function teacher_department_source();

    // Returns student enrollment information for a given department
    function student_department_source();
}

abstract class enrollment_provider implements enrollment_factory {
    // Simple settings array('key' => 'default');
    var $settings = array();

    function get_setting($key, $default=false) {
        $attempt = get_config($this->plugin_key(), $key);

        if (isset($this->settings[$key])) {
            $def = empty($this->settings[$key]) ? $default : $this->settings[$key];
        } else {
            $def = $default;
        }

        return empty($attempt) ? $def : $attempt;
    }

    // Override for special behavior hooks
    function preprocess($enrol = null) {
        return true;
    }

    function postprocess($enrol = null) {
        return true;
    }

    function supports_reverse_lookups() {
        $source = $this->teacher_info_source();
        return !empty($source);
    }

    function supports_section_lookups() {
        return !(is_null($this->student_source()) or is_null($this->teacher_source()));
    }

    function supports_department_lookups() {
        return !(is_null($this->teacher_source()) or is_null($this->teacher_department_source()));
    }

    // Optionally return a source for reverse lookups
    function teacher_info_source() {
        return null;
    }

    function teacher_source() {
        return null;
    }

    function teacher_department_source() {
        return null;
    }

    function student_source() {
        return null;
    }

    function student_department_source() {
        return null;
    }

    protected function simple_settings($settings) {
        global $CFG;

        $plugin_key = $this->plugin_key();

        $_s = ues::gen_str($plugin_key);
        foreach ($this->settings as $key => $default) {
            $settings->add(new admin_setting_configtext("$plugin_key/$key",
                $_s($key), $_s("{$key}_desc", $CFG), $default));
        }
    }

    // Override this function for displaying settings on the UES page as well
    public function settings($settings) {

        if (!empty($this->settings)) {
            $settings->add(new admin_setting_heading('provider_heading',
                $this->get_name(), ''));

            $this->simple_settings($settings);
        }
    }

    // Display name
    public static function get_name() {
        $class = get_called_class();
        return get_string('pluginname', $class::plugin_key());
    }

    public static function translate_error($code) {
        $class = get_called_class();
        return get_string($code, $class::plugin_key());
    }

    // Returns the Moodle plugin key for this provider
    public static function plugin_key() {
        return "enrol_ues";
    }
}
