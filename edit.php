<?php
/**
 * @package enrol_ues
 */
require_once '../../config.php';
require_once 'publiclib.php';
require_once $CFG->dirroot . '/course/lib.php';
require_once 'edit_form.php';

$courseid = required_param('id', PARAM_INT);

$course = course_get_format($courseid)->get_course();
$category = $DB->get_record(
    'course_categories', array('id' => $course->category), '*', MUST_EXIST
);


$PAGE->set_pagelayout('admin');
$PAGE->set_url('/course/edit.php', array('id' => $courseid));

require_login($course);

$context = context_course::instance($courseid);

require_capability('moodle/course:update', $context);

// From course/edit.php
$editoroptions = array(
    'maxfiles' => EDITOR_UNLIMITED_FILES,
    'maxbytes' => $CFG->maxbytes,
    'trusttext' => false,
    'noclean' => true,
    'context' => $context
);

$course = file_prepare_standard_editor(
    $course, 'summary', $editoroptions,
    $context, 'course', 'summary', 0
);

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
    rebuild_course_cache($courseid);
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
