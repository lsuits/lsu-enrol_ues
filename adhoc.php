<?php
/**
 * @package enrol_ues
 */
require_once '../../config.php';
require_once 'publiclib.php';

require_login();

if (!is_siteadmin($USER->id)) {
    redirect(new moodle_url('/my'));
}

$confirmed = optional_param('confirmed', null, PARAM_INT);
$success = optional_param('success', null, PARAM_INT);

$_s = ues::gen_str();

$pluginname = $_s('pluginname');

$action = $_s('run_adhoc');

$base_url = new moodle_url('/admin/settings.php', array(
    'section' => 'enrolsettingsues'
));

$PAGE->set_context(context_system::instance());
$PAGE->set_title($pluginname . ': '. $action);
$PAGE->set_heading($pluginname . ': '. $action);
$PAGE->set_url('/enrol/ues/adhoc.php');

$PAGE->set_pagetype('admin-settings-ues-semester-adhoc');
$PAGE->set_pagelayout('admin');

$PAGE->navbar->add($pluginname, $base_url);
$PAGE->navbar->add($action);

if ($confirmed and $data = data_submitted()) {

    // create the task instance
    $full_process = new \enrol_ues\task\full_process_adhoc();

   // queue the task
   \core\task\manager::queue_adhoc_task($full_process);

    redirect(new moodle_url('/enrol/ues/adhoc.php?success=1'));
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading($action);

echo html_writer::tag('p', $_s('run_adhoc_desc'));

if ($success) {
    echo $OUTPUT->notification($_s('run_adhoc_success'), 'notifysuccess');
}

$confirm_url = new moodle_url('/enrol/ues/adhoc.php', array(
    'confirmed' => 1
));
$cancel_url = new moodle_url('/admin/settings.php?section=enrolsettingsues');

// generate a status/confirmation message
$task_status_description = ues::get_task_status_description();

if ($task_status_description) {
    $confirm_msg = $task_status_description . '<br><br>' . $_s('run_adhoc_confirm_msg');
} else {
    $confirm_msg = $_s('run_adhoc_confirm_msg');
}

echo $OUTPUT->confirm($confirm_msg, $confirm_url, $cancel_url);
echo $OUTPUT->footer();
