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

$confirmed = optional_param('confirmed', null, PARAM_INT);

$_s = ues::gen_str();

$pluginname = $_s('pluginname');

$action = $_s('semester_ignore');

$base_url = new moodle_url('/admin/settings.php', array(
    'section' => 'enrolsettingsues'
));

$PAGE->set_context(context_system::instance());
$PAGE->set_title($pluginname . ': '. $action);
$PAGE->set_heading($pluginname . ': '. $action);
$PAGE->set_url('/enrol/ues/ignore.php');

$PAGE->set_pagetype('admin-settings-ues-semester-ignore');
$PAGE->set_pagelayout('admin');

$PAGE->navbar->add($pluginname, $base_url);
$PAGE->navbar->add($action);

$semesters = ues_semester::get_all(array(), true);

if ($confirmed and $data = data_submitted()) {
    $semesterids = explode(',', $confirmed);

    foreach (get_object_vars($data) as $field => $value) {
        if (!preg_match('/semester_(\d+)/', $field, $ids)) {
            continue;
        }

        if (!isset($semesters[$ids[1]])) {
            continue;
        }

        $semester = $semesters[$ids[1]];
        $semester->semester_ignore = $value;

        $semester->save();
    }

    redirect(new moodle_url('/enrol/ues/ignore.php'));
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading($action);

if ($posts = data_submitted()) {

    $post_params = array();

    $data = html_writer::start_tag('ul');
    foreach (get_object_vars($posts) as $field => $value) {
        if (!preg_match('/semester_(\d+)/', $field, $matches)) {
            continue;
        }

        $id = $matches[1];

        if (!isset($semesters[$id])) {
            continue;
        }

        $semester = $semesters[$id];
        $curr = isset($semester->semester_ignore) ? $semester->semester_ignore : 0;

        // Filter same value
        if ($curr == $value) {
            continue;
        }

        $sem_s = $semester->__toString();
        $stm = empty($value) ? $_s('be_recoged', $sem_s) : $_s('be_ignored', $sem_s);

        $data .= html_writer::tag('li', $stm);
        $post_params[$field] = $value;
    }
    $data .= html_writer::end_tag('ul');

    $msg = $_s('please_note', $data);
    $confirm_url = new moodle_url('/enrol/ues/ignore.php', $post_params + array(
        'confirmed' => 1
    ));
    $cancel_url = new moodle_url('/enrol/ues/ignore.php');

    echo $OUTPUT->confirm($msg, $confirm_url, $cancel_url);
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = array(
    $_s('year'), get_string('name'), $_s('campus'), $_s('session_key'),
    $_s('sections'), $_s('ignore')
);

$table->data = array();

foreach ($semesters as $semester) {

    $name = 'semester_' . $semester->id;

    $hidden_params = array(
        'name' => $name,
        'type' => 'hidden',
        'value' => 0
    );

    $checkbox_params = array(
        'name' => $name,
        'type' => 'checkbox',
        'value' => 1
    );

    if (!empty($semester->semester_ignore)) {
        $checkbox_params['checked'] = 'CHECKED';
    }

    $line = array(
        $semester->year,
        $semester->name,
        $semester->campus,
        $semester->session_key,
        ues_section::count(array('semesterid' => $semester->id)),
        html_writer::empty_tag('input', $hidden_params) .
        html_writer::empty_tag('input', $checkbox_params)
    );

    $table->data[] = new html_table_row($line);
}

echo html_writer::start_tag('form', array('method' => 'POST'));
echo html_writer::table($table);

echo html_writer::start_tag('div', array('class' => 'buttons'));

echo html_writer::empty_tag('input', array(
    'type' => 'submit',
    'name' => 'ignore',
    'value' => $_s('ignore')
));

echo html_writer::end_tag('div');
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
