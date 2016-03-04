<?php

/**
 * UES Guard
 * @package enrol_ues
 *
 * This class handles permissions within the UES package
 * 
 * @throws UESGuardException
 */
abstract class ues_guard {

	/**
	 * Determines whether or not the UES full process is able to be run
	 * 
	 * @throws UESGuardException
	 * @return boolean
	 */
	public static function checkFullProcess($priority = '') {

        if (self::isForcedRun($priority))
            return true;

        // check if is currently running
        if (self::processIsRunning())
        	self::denyPermission('Process is still running.');

        // check if enough time has elapsed since last run (referrencing grace period)
        if (self::processIsWithinGracePeriod())
            self::denyPermission('Process is still within grace period.');

        // check if this is a task, and if so, if it has been disabled
        if ( self::processTaskIsDisabled() and ! self::isAdhocRun($priority) )
            self::denyPermission('Scheduled task is disabled.');

        return true;
    }

    private static function denyPermission($message = 'Permission denied!') {
    	ues::require_exceptions();
    	
    	throw new UESGuardException($message);
    }

    /**
     * Returns whether or not UES is currently running
     * 
     * @return boolean
     */
    private static function processIsRunning() {
        return (bool)self::config('running');
    }

    /**
     * Returns whether or not enough time has elapsed since last run with reference to configured grace period
     * 
     * @return boolean
     */
    private static function processIsWithinGracePeriod() {

        $grace_period = (int)self::config('grace_period');

        if ( ! $grace_period)
            return false;

        $task = self::getScheduledTask();

        $last_run = (int)$task->get_last_run_time();

        $within_grace_period = (time() - $last_run) < $grace_period;

        if ($within_grace_period)
            return true;

        return false;
    }

    /**
     * Returns whether or not the UES scheduled task is disabled
     * 
     * @return boolean
     */
    private static function processTaskIsDisabled() {

        $task = self::getScheduledTask();

        if ( ! $task) {
            return false;
        }

        $isDisabled = $task->get_disabled();

        return $isDisabled;
    }

    /**
     * Returns the scheduled UES task 
     * 
     * @return \core\task\scheduled_task
     */
    private static function getScheduledTask() {
        $task = \core\task\manager::get_scheduled_task('\enrol_ues\task\full_process');

        return $task;
    }

    /**
     * Sets or gets a UES config value
     * 
     * @param  string  $key
     * @param  mixed   $value
     * @return mixed 
     */
    private static function config($key, $value = false) {
        if ($value) {
            return set_config($key, $value, 'enrol_ues');
        } else {
            return get_config('enrol_ues', $key);
        }
    }

    /**
     * Returns whether or not this enrollment command was run 'forced'
     * 
     * @param  string  $priority
     * @return boolean
     */
    private static function isForcedRun($priority = '')
    {
        return ($priority === 'forced');
    }

    /**
     * Returns whether or not this enrollment command was run 'adhoc'
     * 
     * @param  string  $priority
     * @return boolean
     */
    private static function isAdhocRun($priority = '')
    {
        return ($priority === 'adhoc');
    }

    






    ///////////////// OLD STUFF TO BE DELETED WHEN DONE ////////////////////
    


    private static function OLDprocessIsRunning() {
        // do not run task if currently running
        if ((bool)$this->config('running')) {

            global $CFG;
            $url = $CFG->wwwroot . '/admin/settings.php?section=enrolsettingsues';
            $this->logError(ues::_s('already_running', $url));

            $this->email_reports();

            return false;
        }
    }

    private static function OLDprocessIsWithinGracePeriod() {
        // do not run task if time elapsed since last run is less than grace period
        $last_run = (int)$task->get_last_run_time();
        $grace_period = (int)$this->config('grace_period');
        $within_grace_period = (time() - $last_run) < $grace_period;

        if ($within_grace_period) {

            global $CFG;
            $url = $CFG->wwwroot . '/admin/settings.php?section=enrolsettingsues';
            $this->logError(ues::_s('within_grace_period', $url));

            $this->email_reports();

            return false;
        }
    }

    private static function OLDprocessTaskIsDisabled() {
        // get scheduled task
        $task = \core\task\manager::get_scheduled_task('\enrol_ues\task\full_process');

        // allow to run if there is no scheduled task
        if ( ! $task) {
            return true;
        }

        // do not run task if disabled
        if ( ! $adhoc and $task->get_disabled()) {
            $this->logError(ues::_s('task_disabled', $url));

            $this->email_reports();

            return false;
        }
    }

}