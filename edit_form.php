<?php
/**
 * @package enrol_ues
 */
require_once $CFG->dirroot . '/course/edit_form.php';

class ues_course_edit_form extends course_edit_form {
    function definition() {
        global $USER, $DB;
        parent::definition();

        $m =& $this->_form;

        $restricted = get_config('enrol_ues', 'course_restricted_fields');
        $restricted_fields = explode(',', $restricted);

        $system = context_system::instance();
        $can_change = has_capability('moodle/course:update', $system);

        foreach ($restricted_fields as $field) {
            if ($can_change) {
                continue;
            }
            $m->removeElement($field);
        }

        $disable_grouping = (
            in_array('groupmode', $restricted_fields) and
            in_array('groupmodeforce', $restricted_fields)
        );

        if ($disable_grouping) {
            $m->hardFreeze('defaultgroupingid');
            $m->removeElement('groups');
        }

        $roles = $DB->get_records('role');
        foreach ($roles as $id => $role) {
            $name = 'role_' . $id;
            if ($m->elementExists($name)) {
                $m->removeElement($name);
            }
        }

        $m->removeElement('rolerenaming');
    }
}
