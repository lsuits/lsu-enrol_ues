<?php

/**
 * An adhoc task.
 *
 * Top-level UES function. Executes pre-process, process and post-process phases.
 *
 * @package    enrol_ues
 * @copyright  2015 Louisiana State University
 */
namespace enrol_ues\task;

class full_process_adhoc extends \core\task\adhoc_task {

    /**
     * Do the job.
     *
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {

        global $CFG;
        require_once($CFG->dirroot . '/enrol/ues/lib.php');
        $ues = new \enrol_ues_plugin();
        $ues->run_enrollment_process(true);

    }
}
