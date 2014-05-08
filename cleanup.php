<?php
/**
 * @package enrol_ues
 */
require_once '../../config.php';
require_once 'publiclib.php';

ues::require_daos();

require_login();

if (!is_siteadmin($USER->id)) {
    redirect(new moodle_url('/my'));
}

$semesterid = optional_param('id', NULL, PARAM_INT);

$_s = ues::gen_str();

$blockname = $_s('pluginname');

$action = $_s('semester_cleanup');

$base_url = new moodle_url('/admin/settings.php', array(
    'section' => 'enrolsettingsues'
));

$PAGE->set_context(context_system::instance());
$PAGE->set_title($blockname. ': '. $action);
$PAGE->set_heading($blockname. ': '. $action);
$PAGE->set_url('/enrol/ues/cleanup.php');
$PAGE->set_pagetype('admin-settings-ues-semester-cleanup');
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add($blockname, $base_url);

$PAGE->navbar->add($action);

echo $OUTPUT->header();
echo $OUTPUT->heading($action);

if ($semesterid) {
    $semester_param = array('id' => $semesterid);

    $semester = ues_semester::get($semester_param);

    $base = '/enrol/ues/cleanup.php';

    if (empty($semester)) {
        print_error('no_semester', 'enrol_ues');
    }

    if (data_submitted()) {
        // Report the drop
        echo $OUTPUT->box_start();
        echo html_writer::start_tag('pre');
        ues::drop_semester($semester, true);
        echo html_writer::end_tag('pre');
        echo $OUTPUT->box_end();

        echo $OUTPUT->continue_button(new moodle_url($base));
    } else {
        $continue = new moodle_url($base, $semester_param);
        $cancel = new moodle_url($base);

        echo $OUTPUT->confirm($_s('drop_semester', $semester), $continue, $cancel);
    }

    echo $OUTPUT->footer();
    die();
}

$semesters = ues_semester::get_all();
$in_session = ues_semester::in_session();

if (empty($semesters)) {
    echo $OUTPUT->box_start();
    echo $OUTPUT->notification($_s('no_semesters'));
    echo $OUTPUT->box_end();
    echo $OUTPUT->continue_button($base_url);
    echo $OUTPUT->footer();
    die();
}

$table = new html_table();

$table->head = array(
    $_s('year'), get_string('name'), $_s('campus'), $_s('session_key'),
    $_s('sections'), $_s('in_session'), get_string('action')
);

$table->data = array();

$make_remove_link = function($semester) use ($OUTPUT, $_s) {
    $remove_icon = $OUTPUT->pix_icon('i/cross_red_big', $_s('drop_semester', $semester));
    $url = new moodle_url('/enrol/ues/cleanup.php', array('id' => $semester->id));

    return html_writer::link($url, $remove_icon);
};

foreach ($semesters as $semester) {

    $line = array(
        $semester->year,
        $semester->name,
        $semester->campus,
        $semester->session_key,
        ues_section::count(array('semesterid' => $semester->id)),
        isset($in_session[$semester->id]) ? 'Y' : 'N',
        $make_remove_link($semester)
    );

    $table->data[] = new html_table_row($line);
}

echo html_writer::table($table);

echo $OUTPUT->footer();
