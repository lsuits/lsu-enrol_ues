<?php

define('CLI_SCRIPT', true);

require_once '../../../config.php';
global $CFG;
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot . '/enrol/ues/publiclib.php');
require_once($CFG->dirroot . '/enrol/ues/lib.php');

// get cli options
list($options, $unrecognized) = cli_get_params(array('help'=>false), array('h'=>'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    echo "
****************************
UES Enrollment CLI commands.
****************************

Options:
-h, --help            Print out this help

Example:
\$sudo -u www-data /usr/bin/php enrol/ues/cli/enrol.php

";
    die;
}

$response = ues::runEnrollment();

echo $response . '
';
die;