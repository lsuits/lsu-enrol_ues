<?php
/**
 * @package enrol_ues
 */
defined('MOODLE_INTERNAL') or die();

$plugin->component = 'enrol_ues';
$plugin->version = 2016083000;
$plugin->requires = 2015051102;
$plugin->cron = 43200; // @TODO - remove this in favor of the scheduled task API
$plugin->release = 'v3.1.0';