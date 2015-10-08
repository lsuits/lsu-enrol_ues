<?php

/**
 * Definition of UES enrolment scheduled tasks.
 *
 * @package   enrol_ues
 * @category  task
 * @copyright 2015 Louisiana State University
 */

defined('MOODLE_INTERNAL') || die();

$tasks = array(
    array(
        'classname' => 'enrol_ues\task\full_process',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '22',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*'
    )
);
