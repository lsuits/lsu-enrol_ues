<?php
/**
 * @package enrol_ues
 */
defined('MOODLE_INTERNAL') or die();

$plugin->component = 'enrol_ues';
$plugin->version = 2015092308;
$plugin->requires = 2015051102;
$plugin->cron = 43200; // @todo - remove this in favor of the scheduled task API
// $plugin->maturity = MATURITY_ALPHA;
$plugin->release = 'v3.0.0';
