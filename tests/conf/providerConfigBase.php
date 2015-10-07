<?php

require_once 'configBase.php';

/**
 * extend this class to use your local values,
 * replacing the array elements with your own
 */
class ProviderConfigBase extends ConfigBase {

    //local provider settings
    private $config = array(
        array('course_form_replace',       'default', 'local_provider'),
        array('course_fullname',           'default', 'local_provider'),
        array('course_restricted_fields',  'default', 'local_provider'),
        array('course_shortname',          'default', 'local_provider'),
        array('editingteacher_role',       'default', 'local_provider'),
        array('email_report',              'default', 'local_provider'),
        array('enrollment_provider',       'default', 'local_provider'),
        array('error_threshold',           'default', 'local_provider'),
        array('grace_period',              'default', 'local_provider'),
        array('process_by_department',     'default', 'local_provider'),
        array('recover_grades',            'default', 'local_provider'),
        array('running',                   'default', 'local_provider'),
        array('student_role',              'default', 'local_provider'),
        array('sub_days',                  'default', 'local_provider'),
        array('teacher_role',              'default', 'local_provider'),
        array('user_auth',                 'default', 'local_provider'),
        array('user_city',                 'default', 'local_provider'),
        array('user_confirm',              'default', 'local_provider'),
        array('user_country',              'default', 'local_provider'),
        array('user_email',                'default', 'local_provider'),
        array('user_lang',                 'default', 'local_provider'),
        array('version',                   'default', 'local_provider'),
    );
}
?>
