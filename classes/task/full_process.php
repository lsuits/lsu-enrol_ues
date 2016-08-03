<?php

/**
 * A scheduled task.
 *
 * Top-level UES function. Executes pre-process, process and post-process phases.
 *
 * @package    enrol_ues
 * @copyright  2015 Louisiana State University
 */
namespace enrol_ues\task;

class full_process extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {

        return get_string('full_process_task', 'enrol_ues');

    }

    /**
     * Do the job.
     *
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {

        global $CFG;
        require_once($CFG->dirroot . '/enrol/ues/lib.php');
        $ues = new \enrol_ues_plugin();
        $ues->run_enrollment_process();

    }
}
