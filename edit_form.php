<?php

require_once $CFG->dirroot . '/course/edit_form.php';

class ues_course_edit_form extends course_edit_form {
    function definition() {
        global $USER;
        parent::definition();

        $m =& $this->_form;

        $restricted = get_config('enrol_ues', 'course_restricted_fields');
        $restricted_fields = explode(',', $restricted);

        $system = get_context_instance(CONTEXT_SYSTEM);
        $can_change = has_capability('moodle/course:update', $system);

        foreach ($restricted_fields as $field) {
            if ($can_change) {
                continue;
            }
            $m->removeElement($field);
        }
    }
}
