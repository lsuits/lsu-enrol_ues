<?php

namespace enrol_ues\event;

defined('MOODLE_INTERNAL') || die();

class ues_event_base extends \core\event\base {

    /**
     * Initialize the event
     *
     * @return void
     */
    protected function init() {
        $this->context = \context_system::instance();
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

}