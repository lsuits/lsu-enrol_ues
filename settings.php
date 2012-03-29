<?php

defined('MOODLE_INTERNAL') or die();

if ($ADMIN->fulltree) {
    require_once dirname(__FILE__) . '/publiclib.php';

    $plugins = ues::list_plugins();

    $_s = ues::gen_str();

    $settings->add(new admin_setting_heading('enrol_ues_settings', '',
        $_s('pluginname_desc', ues::plugin_base())));

    $urls = new stdClass;
    $urls->cleanup_url = $CFG->wwwroot . '/enrol/ues/cleanup.php';
    $urls->failure_url = $CFG->wwwroot . '/enrol/ues/failures.php';

    $settings->add(new admin_setting_heading('enrol_ues_internal_links',
        $_s('management'), $_s('management_links', $urls)));

    // --------------------- General Settings --------------------------------
    $settings->add(new admin_setting_heading('enrol_ues_genernal_settings',
        $_s('general_settings'), ''));

    $settings->add(new admin_setting_configselect('enrol_ues/enrollment_provider',
        $_s('provider'), $_s('provider_desc'), 'fake', $plugins));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/process_by_department',
        $_s('process_by_department'), $_s('process_by_department_desc'), 1));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/cron_run',
        $_s('cron_run'), $_s('cron_run_desc'), 1));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/running',
        $_s('running'), $_s('running_desc'), 0));

    $settings->add(new admin_setting_configtext('enrol_ues/starttime',
        $_s('starttime'), $_s('starttime_desc'), 0));

    $settings->add(new admin_setting_configtext('enrol_ues/grace_period',
        $_s('grace_period'), $_s('grace_period_desc'), 3600));

    $hours = range(0, 23);

    $settings->add(new admin_setting_configselect('enrol_ues/cron_hour',
        $_s('cron_hour'), $_s('cron_hour_desc'), 2, $hours));

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

    $auths = get_plugin_list('auth');
    $ath_options = array();
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
        'format' => get_string('format'),
        'numsections' => get_string('numberweeks'),
        'hiddensections' => get_string('hiddensections'),
        'newsitems' => get_string('newsitemsnumber'),
        'showgrades' => get_string('showgrades'),
        'showreports' => get_string('showreports'),
        'maxbytes' => get_string('maximumupload'),
        'legacyfiles' => get_string('courselegacyfiles'),
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

    $roles = $DB->get_records_menu('role', null, '', 'id, name');

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
        $reg_settings = $provider::settings();

        $adv_settings = $provider::adv_settings();

        if ($reg_settings or $adv_settings) {
            $plugin_name = $_s($provider::get_name() . '_name');
            $settings->add(new admin_setting_heading('provider_settings',
                $_s('provider_settings', $plugin_name), ''));
        }

        if ($reg_settings) {
            foreach ($reg_settings as $key => $default) {
                $actual_key = $provider::get_name() . '_' . $key;
                $settings->add(new admin_setting_configtext('enrol_ues/'.$actual_key,
                    $_s($actual_key), $_s($actual_key.'_desc', $CFG), $default));
            }
        }

        if ($adv_settings) {
            foreach ($adv_settings as $setting) {
                $settings->add($setting);
            }
        }

        try {
            // Attempting to create the provider
            $test_provider = new $provider();

            $works = (
                $test_provider->supports_section_lookups() or
                $test_provider->supports_department_lookups()
            );

            if ($works === false) {
                throw new Exception('enrollment_unsupported');
            }

            $a = new stdClass;
            $a->name = $plugin_name;
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
