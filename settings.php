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

    $hours = range(0, 23);

    $settings->add(new admin_setting_configselect('enrol_ues/cron_hour',
        $_s('cron_hour'), $_s('cron_hour_desc'), 2, $hours));

    $settings->add(new admin_setting_configtext('enrol_ues/error_threshold',
        $_s('error_threshold'), $_s('error_threshold_desc'), 100));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/email_report',
        $_s('email_report'), $_s('email_report_desc'), 1));

    $settings->add(new admin_setting_heading('enrol_ues_user_settings',
        $_s('user_settings'), ''));

    $settings->add(new admin_setting_configtext('enrol_ues/user_email',
        $_s('user_email'), $_s('user_email_desc'), '@example.com'));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/user_confirm',
        $_s('user_confirm'), $_s('user_confirm_desc'), 1));

    $settings->add(new admin_setting_configtext('enrol_ues/user_city',
        $_s('user_city'), $_s('user_city_desc'), ''));

    $countries = get_string_manager()->get_list_of_countries();
    $settings->add(new admin_setting_configselect('enrol_ues/user_country',
        $_s('user_country'), $_s('user_country_desc'), $CFG->country, $countries));

    $settings->add(new admin_setting_heading('enrol_ues_course_settings',
        $_s('course_settings'), ''));

    $settings->add(new admin_setting_configtext('enrol_ues/course_shortname',
        get_string('shortname'), $_s('course_shortname_desc'),
        $_s('course_shortname')));

    $courseformats = get_plugin_list('format');
    $formats = array();
    foreach ($courseformats as $format => $dir) {
        $formats[$format] = get_string('pluginname', 'format_' . $format);
    }

    $settings->add(new admin_setting_configselect('enrol_ues/course_format',
        get_string('format'), $_s('course_format_desc'), 'weeks', $formats));

    $options = array_combine(range(1, 52), range(1, 52));
    $settings->add(new admin_setting_configselect('enrol_ues/course_numsections',
        get_string('numberweeks'), $_s('course_numsections_desc'), 17, $options));

    $settings->add(new admin_setting_configcheckbox('enrol_ues/course_visible',
        get_string('visible'), $_s('course_visible_desc'), 0));

    $settings->add(new admin_setting_heading('enrol_ues_enrol_settings',
        $_s('enrol_settings'), ''));

    $roles = $DB->get_records_menu('role', null, '', 'id, name');

    foreach (array('editingteacher', 'teacher', 'student') as $shortname) {
        $typeid = $DB->get_field('role', 'id', array('shortname' => $shortname));

        $settings->add(new admin_setting_configselect('enrol_ues/'.$shortname.'_role',
            $_s($shortname.'_role'), $_s($shortname.'_role_desc'), $typeid ,$roles));
    }

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
