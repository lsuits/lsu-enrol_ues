<?php
/**
 * @package enrol_ues
 */
defined('MOODLE_INTERNAL') or die();

if ($ADMIN->fulltree) {
    require_once dirname(__FILE__) . '/publiclib.php';

    $plugins = ues::list_plugins();

    $_s = ues::gen_str();

    $settings->add(new admin_setting_heading('enrol_ues_settings', '',
        $_s('pluginname_desc')));

    // --------------------- Scheduled Task Status --------------------------------

    $settings->add(new admin_setting_heading('enrol_ues_task_status',
        $_s('task_status'), ues::get_task_status_description()));

    // --------------------- Internal Links --------------------------------

    $urls = new stdClass;
    $urls->cleanup_url = $CFG->wwwroot . '/enrol/ues/cleanup.php';
    $urls->failure_url = $CFG->wwwroot . '/enrol/ues/failures.php';
    $urls->ignore_url = $CFG->wwwroot . '/enrol/ues/ignore.php';
    $urls->adhoc_url = $CFG->wwwroot . '/enrol/ues/adhoc.php';

    $settings->add(new admin_setting_heading('enrol_ues_internal_links',
        $_s('management'), $_s('management_links', $urls)));

    // --------------------- General Settings --------------------------------

    $settings->add(new admin_setting_heading('enrol_ues_general_settings',
        $_s('general_settings'), ''));

    if (!empty($plugins)) {
        $settings->add(new admin_setting_configselect('enrol_ues/enrollment_provider',
            $_s('provider'), $_s('provider_desc'), key($plugins), $plugins));
    }

    $settings->add(new admin_setting_configcheckbox('enrol_ues/process_by_department',
        $_s('process_by_department'), $_s('process_by_department_desc'), 1));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/running',
        $_s('running'), $_s('running_desc'), 0));

    $settings->add(new admin_setting_configtext('enrol_ues/grace_period',
        $_s('grace_period'), $_s('grace_period_desc'), 3600));

    $settings->add(new admin_setting_configtext('enrol_ues/sub_days',
        $_s('sub_days'), $_s('sub_days_desc'), 60));

    $settings->add(new admin_setting_configtext('enrol_ues/error_threshold',
        $_s('error_threshold'), $_s('error_threshold_desc'), 100));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/email_report',
        $_s('email_report'), $_s('email_report_desc'), 1));

    // ------------------ User Creation Settings -----------------------------
    $settings->add(new admin_setting_heading('enrol_ues_user_settings',
        $_s('user_settings'), ''));

    $settings->add(new admin_setting_configtext('enrol_ues/user_email',
        $_s('user_email'), $_s('user_email_desc'), '@example.com'));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/user_confirm',
        $_s('user_confirm'), $_s('user_confirm_desc'), 1));

    $languages = get_string_manager()->get_list_of_translations();
    $settings->add(new admin_setting_configselect('enrol_ues/user_lang',
        get_string('language'), '', $CFG->lang, $languages));

    $auths = get_plugin_list('auth');
    $auth_options = array();
    foreach ($auths as $auth => $unused) {
        $auth_options[$auth] = get_string('pluginname', "auth_{$auth}");
    }

    $settings->add(new admin_setting_configselect('enrol_ues/user_auth',
        $_s('user_auth'), $_s('user_auth_desc'), 'manual', $auth_options));

    $settings->add(new admin_setting_configtext('enrol_ues/user_city',
        $_s('user_city'), $_s('user_city_desc'), ''));

    $countries = get_string_manager()->get_list_of_countries();
    $settings->add(new admin_setting_configselect('enrol_ues/user_country',
        $_s('user_country'), $_s('user_country_desc'), $CFG->country, $countries));

    // ------------------ Course Creation Settings ---------------------------
    $settings->add(new admin_setting_heading('enrol_ues_course_settings',
        $_s('course_settings'), ''));

    $settings->add(new admin_setting_configtext('enrol_ues/course_fullname',
        get_string('fullname'), '', $_s('course_shortname')));

    $settings->add(new admin_setting_configtext('enrol_ues/course_shortname',
        get_string('shortname'), $_s('course_shortname_desc'),
        $_s('course_shortname')));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/course_form_replace',
        $_s('course_form_replace'), $_s('course_form_replace_desc'), 0));

    $fields = array(
        'newsitems' => get_string('newsitemsnumber'),
        'showgrades' => get_string('showgrades'),
        'showreports' => get_string('showreports'),
        'maxbytes' => get_string('maximumupload'),
        'groupmode' => get_string('groupmode'),
        'groupmodeforce' => get_string('groupmodeforce'),
        'lang' => get_string('forcelanguage')
    );

    $defaults = array('groupmode', 'groupmodeforce');

    $settings->add(new admin_setting_configmultiselect('enrol_ues/course_restricted_fields',
        $_s('course_restricted_fields'), $_s('course_restricted_fields_desc'),
        $defaults, $fields));

    // ------------------ User Enrollment Settings ---------------------------
    $settings->add(new admin_setting_heading('enrol_ues_enrol_settings',
        $_s('enrol_settings'), ''));

    $roles = role_get_names(null, null, true);

    foreach (array('editingteacher', 'teacher', 'student') as $shortname) {
        $typeid = $DB->get_field('role', 'id', array('shortname' => $shortname));
        $settings->add(new admin_setting_configselect('enrol_ues/'.$shortname.'_role',
            $_s($shortname.'_role'), $_s($shortname.'_role_desc'), $typeid ,$roles));
    }

    $settings->add(new admin_setting_configcheckbox('enrol_ues/recover_grades',
        $_s('recover_grades'), $_s('recover_grades_desc'), 1));


    // ------------------ Specific Provider Settings -------------------------
    $provider = ues::provider_class();

    if ($provider) {
        try {
            // Attempting to create the provider
            $test_provider = new $provider();

            $test_provider->settings($settings);

            $works = (
                $test_provider->supports_section_lookups() or
                $test_provider->supports_department_lookups()
            );

            if ($works === false) {
                throw new Exception('enrollment_unsupported');
            }

            $a = new stdClass;
            $a->name = $test_provider->get_name();
            $a->list = '';

            if ($test_provider->supports_department_lookups()) {
                $a->list .= '<li>' . ues::_s('process_by_department') . '</li>';
            }

            if ($test_provider->supports_section_lookups()) {
                $a->list .= '<li>' . ues::_s('process_by_section') . '</li>';
            }

            if ($test_provider->supports_reverse_lookups()) {
                $a->list .= '<li>' . ues::_s('reverse_lookups') . '</li>';
            }

            $settings->add(new admin_setting_heading('provider_information',
                $_s('provider_information'), $_s('provider_information_desc', $a)));
        } catch (Exception $e) {
            $a = ues::translate_error($e);

            $settings->add(new admin_setting_heading('provider_problem',
                $_s('provider_problems'), $_s('provider_problems_desc', $a)));
        }
    }
}
