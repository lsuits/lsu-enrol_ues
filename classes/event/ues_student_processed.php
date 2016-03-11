<?php

namespace enrol_ues\event;

defined('MOODLE_INTERNAL') || die();

class ues_student_processed extends \enrol_ues\event\ues_event_base {

    /**
     * Initialize the event
     *
     * @return void
     */
    protected function init() {
        parent::init();
        $this->data['crud'] = 'r';
    }

}