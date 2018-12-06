<?php
require_once 'configBase.php';

/**
 * extend this class to use your local values,
 * replacing the array elements with your own
 */
class UesConfigBase extends ConfigBase{

    //enrol/ues settings
    private $config = array(
        array('course_form_replace',       'default', 'enrol_ues'),
        array('course_fullname',           'default', 'enrol_ues'),
        array('course_restricted_fields',  'default', 'enrol_ues'),
        array('course_shortname',          'default', 'enrol_ues'),
        array('editingteacher_role',       'default', 'enrol_ues'),
        array('email_report',              'default', 'enrol_ues'),
        array('enrollment_provider',       'default', 'enrol_ues'),
        array('error_threshold',           'default', 'enrol_ues'),
        array('grace_period',              'default', 'enrol_ues'),
        array('process_by_department',     'default', 'enrol_ues'),
        array('recover_grades',            'default', 'enrol_ues'),
        array('running',                   'default', 'enrol_ues'),
        array('student_role',              'default', 'enrol_ues'),
        array('sub_days',                  'default', 'enrol_ues'),
        array('teacher_role',              'default', 'enrol_ues'),
        array('user_auth',                 'default', 'enrol_ues'),
        array('user_city',                 'default', 'enrol_ues'),
        array('user_confirm',              'default', 'enrol_ues'),
        array('user_country',              'default', 'enrol_ues'),
        array('user_email',                'default', 'enrol_ues'),
        array('user_lang',                 'default', 'enrol_ues'),
        array('version',                   'default', 'enrol_ues'),
    );

    public function getUesConfigs(){
        return $this->config;
    }

    public function setUesConfigs(){
        foreach($this->config as $conf){
            set_config(implode(',',$conf));
        }
    }
}
?>
