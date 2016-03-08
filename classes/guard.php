<?php

/**
 * UES Guard
 * @package enrol_ues
 *
 * This class handles permissions within the UES package by throwing 'guard' exceptions.
 * 
 * @throws UESGuardException
 */
abstract class ues_guard {

	/**
	 * Determines whether or not the UES full process is able to be run
	 * 
     * @param   enrol_ues_plugin   a UES instantiation
     * @param   string  (optional) forced|adhoc
     * @throws  UESGuardException
	 * @return boolean
	 */
	public static function check($ues, $priority = '') {

        if (self::isForcedRun($priority))
            return true;

        // check if is currently running
        if ($ues->isRunning())
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
    	ues::requireExceptionLibs();
    	
    	throw new UESGuardException($message);
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

}