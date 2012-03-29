<?php

require_once '../../config.php';
require_once 'publiclib.php';
require_once $CFG->dirroot . '/course/lib.php';
require_once 'edit_form.php';

$courseid = required_param('id', PARAM_INT);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$category = $DB->get_record(
    'course_categories', array('id' => $course->category), '*', MUST_EXIST
);

$PAGE->set_pagelayout('admin');
$PAGE->set_url('/course/edit.php', array('id' => $courseid));

require_login($course);

$context = get_context_instance(CONTEXT_COURSE, $courseid);

require_capability('moodle/course:update', $context);

// From course/edit.php
$editoroptions = array(
    'maxfiles' => EDITOR_UNLIMITED_FILES,
    'maxbytes' => $CFG->maxbytes,
    'trusttext' => false,
    'noclean' => true
);

$allowedmodules = array();
if ($am = $DB->get_records('course_allowed_modules', array('course'=>$course->id))) {
    foreach ($am as $m) {
        $allowedmodules[] = $m->module;
    }
} else {
    if (empty($course->restrictmodules) and !empty($CFG->defaultallowedmodules)) {
        $allowedmodules[] = explode(',', $CFG->defaultallowedmodules);
    }
}

$course->allowedmods = $allowedmodules;
$editoroptions['context'] = $context;
$course = file_prepare_standard_editor($course, 'summary', $editoroptions, null, 'course', 'summary',0);

$form = new ues_course_edit_form(null, array(
    'course' => $course,
    'category' => $category,
    'editoroptions' => $editoroptions,
    'returnto' => null
));

$return = new moodle_url('/course/view.php', array('id' => $courseid));

if ($form->is_cancelled()) {
    redirect($return);
} else if ($data = $form->get_data()) {
    update_course($data, $editoroptions);
    redirect($return);
}

$streditsettings = get_string('editcoursesettings');
$PAGE->navbar->add($streditsettings);
$PAGE->set_title($streditsettings);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading($streditsettings);

$form->display();

echo $OUTPUT->footer();
