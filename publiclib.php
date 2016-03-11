<?php

/**
 * @package enrol_ues
 */
defined('MOODLE_INTERNAL') or die();

abstract class ues {
    
    const PENDING = 'pending';
    const PROCESSED = 'processed';

    // Section is created
    const MANIFESTED = 'manifested';
    const SKIPPED = 'skipped';

    // Teacher / Student manifestation
    const ENROLLED = 'enrolled';
    const UNENROLLED = 'unenrolled';

    /**
     * Loads all UES libraries
     * 
     * @return null
     */
    public static function requireLibs() {
        self::requireDaoLibs();
        self::requireExtensionLibs();
        self::requireExceptionLibs();
    }

    /**
     * Loads all UES data access object libraries
     * 
     * @return null
     */
    public static function requireDaoLibs() {
        $dao = self::base('classes/dao');

        require_once $dao . '/base.php';
        require_once $dao . '/extern.php';
        require_once $dao . '/lib.php';
        require_once $dao . '/daos.php';
        require_once $dao . '/error.php';
        require_once $dao . '/filter.php';
    }

    /**
     * Loads all UES extension and exception libraries
     * 
     * @return null
     */
    public static function requireExtensionLibs() {
        $classes = self::base('classes');

        require_once $classes . '/processors.php';
        require_once $classes . '/provider.php';
        require_once $classes . '/guard.php';

        self::requireExceptionLibs();
    }

    /**
     * Loads all UES exception libraries
     * 
     * @return null
     */
    public static function requireExceptionLibs() {
        $exceptions = self::base('classes/exceptions');

        require_once $exceptions . '/UESGuardException.php';
        require_once $exceptions . '/UESProviderException.php';
    }

    /**
     * Helper class for returing base directory path for given UES directory
     * 
     * @param  string  $dir
     * @return string
     */
    public static function base($dir = '') {
        $path = empty($dir) ? '' : '/' . $dir;

        return dirname(__FILE__) . $path;
    }

    /**
     * Helper class for returning a localized string for UES package
     * 
     * @param  string  $key
     * @param  string  $a
     * @return string
     */
    public static function _s($key, $a = null) {
        return get_string($key, 'enrol_ues', $a);
    }

    /**
     * Helper class for generating a localized string from a moodle plugin (UES by default)
     * 
     * @param  string  $plugin 
     * @return string
     */
    public static function gen_str($plugin = 'enrol_ues') {
        return function ($key, $a = null) use ($plugin) {
            return get_string($key, $plugin, $a);
        };
    }

    /**
     * Helper class for formatting a timestamp to YYYY-MM-DD
     * 
     * @param  int  $time  timestamp
     * @return string
     */
    public static function format_time($time) {
        return strftime('%Y-%m-%d', $time);
    }

    /**
     * Helper class for creating UES dao filters
     * 
     * @param  string          $field
     * @return ues_dao_filter
     */
    public static function where($field = null) {
        return new ues_dao_filter($field);
    }

    /**
     * Helper class for formatting a given object using a specified pattern
     * 
     * @param  string    $pattern
     * @param  stdClass  $obj
     * @return string
     */
    public static function format_string($pattern, $obj) {
        foreach (get_object_vars($obj) as $key => $value) {
            $pattern = preg_replace('/\{' . $key . '\}/', $value, $pattern);
        }

        return $pattern;
    }

    /**
     * Returns an instantiated UES implementation of a moodle enrollment plugin
     * 
     * @return  enrol_ues_plugin
     * 
     * @throws  UESException
     */
    public static function getPlugin() {
        
        // attempt to instantiate a UES enrollment plugin
        $ues = enrol_get_plugin('ues');

        if ( ! $ues)
            throw new UESException('Fatal UES error: Could not load UES enrollment plugin!');

        return $ues;
    }

    /**
     * Returns an instance of currently selected UES enrollment provider
     * 
     * @return enrollment_provider
     */
    public static function getProvider() {
        
        try {
            $plugin = self::getPlugin();

            $provider = $plugin->provider();
        } catch(Exception $e) {
            return false;
        }

        return $provider;
    }

    /**
     * Runs the full UES enrollment process with an optional given priority
     * 
     * @param  string  $priority  (forced|adhoc)
     * @return null
     */
    public static function runFullEnrollment($priority = '') {
        
        // require UES libraries
        self::requireLibs();

        try {
            
            // get UES implementation instance of moodle enrol_plugin
            $ues = ues::getPlugin();

            $ues->log('------------------------------------------------');
            $ues->log(' ' . ues::_s('pluginname') . ' has started');
            $ues->log('------------------------------------------------');
            $ues->log();

            $startTime = microtime();

            // check to make sure UES can be run right now
            ues_guard::check($ues, $priority);
            
            // get the configured enrollment provider
            $provider = $ues->provider();

            // check that the provider meets all requirements, throwing an error if not
            ues::checkProviderSupportLookups($provider, array(
                'section', 
                'department'
            ), true);
            
            // set status to running
            $ues->running(true);
            
            // run any provider preprocesses
            $ues->handleProviderPreprocess();

            // provision UES by fetching data from the provider
            $ues->handleProvisioning();

            // manifest moodle enrollment
            $ues->handleEnrollment();
            
            // run any provider postprocesses
            $ues->handleProviderPostprocess();

            $ues->running(false);

            $ues->log('------------------------------------------------');
            $ues->log(' UES Enrollment completed');
            $ues->log('');
            $ues->log(' Errors encountered: ' . $ues->getErrorCount());
            $ues->log('');
            $ues->log(' Time elapsed: ' . microtime_diff($startTime, microtime()) . ' secs');
            $ues->log('------------------------------------------------');
            
            $ues->handleAutomaticErrors(false); // @TODO - remove the 'false' for production, will send emails
            $ues->emailReports(false); // @TODO - remove the 'false' for production, will send emails

        } catch (UESGuardException $e) {
            $ues->logError('UES Guard error: ' .$e->getMessage());
            $ues->log();
            $ues->running(false);
            $ues->emailReports();
        } catch (UESProviderException $e) {
            $ues->logError('UES Provider error: ' .$e->getMessage());
            $ues->log();
            $ues->running(false);
            $ues->emailReports();
        } catch (UESException $e) {
            $ues->logError('UES error: ' .$e->getMessage());
            $ues->log();
            $ues->running(false);
            $ues->emailReports();
        } catch (Exception $e) {
            $ues->logError('UES fatal error: ' .$e->getMessage());
            $ues->log();
            $ues->running(false);
            $ues->emailReports();
        }
    }

    /**
     * Returns an array of installed UES provider plugins
     * 
     * @return array ['plugin_name' => 'Plugin name']
     */
    public static function listAvailableProviders() {
        
        // instantiate UES
        $ues = self::getPlugin();

        return ( ! $ues) ? array() : $ues->listProviders();
    }

    /**
     * Checks that a specific provider instance supports a given "lookup" method
     * 
     * @param  enrollment_provider  $provider
     * @param  array                $entityKeys         ex: department, section, etc.
     * @param  boolean              $throwsExceptions   will throw an exception on fail if enabled, otherwise a boolean
     * @return mixed
     * 
     * @throws UESProviderException
     */
    public static function checkProviderSupportLookups($provider = false, $entityKeys = array(), $throwsExceptions = false) {

        if ( ! $provider || is_null($provider)) {
            if ($throwsExceptions) {
                throw new UESProviderException('No enrollment provider specified.');
            } else {
                return false;
            }
        }

        foreach ($entityKeys as $entityKey) {

            $supports = 'supports_' . $entityKey . '_lookups';

            if ( ! method_exists($provider, $supports)) {
                if ($throwsExceptions) {
                    throw new UESProviderException("Provider is expecting a '" . $supports . "' method.");
                } else {
                    return false;
                }
            }

            if ( ! $provider->$supports()) {
                if ($throwsExceptions) {
                    throw new UESProviderException('Provider does not support: ' . (ucfirst($entityKey)) . ' lookups');
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempts to reprocess all UES errors
     * 
     * @param  array    $ues_errors[]
     * @param  boolean  $emailReport  whether or not to email a report
     * @return null       
     */
    public static function reprocessErrors($ues_errors, $emailReport = false) {

        $ues = self::getPlugin();

        $errorCount = count($ues_errors);

        // if there are any UES errors, attempt to handle each of them
        if ($errorCount) {
            
            $errorString = ($errorCount === 1) ? 'error' : 'errors';

            $ues->log('-------------------------------------');
            $ues->log('Attempting to reprocess ' . $errorCount . ' ' . $errorString . ':');
            $ues->log('-------------------------------------');
        
            foreach ($ues_errors as $ues_error) {
            
                $ues->log('Handling error code: ' . $ues_error->name);

                // attempt to handle the UES error
                if ($ues_error->handle($ues)) {
                    
                    // if handled properly, update enrollment
                    $ues->handleEnrollment();

                    // remove this error from the queue
                    ues_error::delete($ues_error->id);
                }
            }

            // email report if enabled
            if ($emailReport) {
                $ues->emailReports();
            }
        }
    }

    /**
     * Returns a formatted error object from a given exception for use with various UES "problem" error messages
     * 
     * @param  Exception  $e
     * @return object
     */
    public static function translate_error($e) {
        
        // instantiates a provider
        $provider_class = self::getProvider();

        // gets error message from exception
        $message = $e->getMessage();

        // sets error problem to string from provider or a default
        $error = new stdClass;

        if ($message == 'enrollment_unsupported') {
            $error->problem = self::_s($message);
        } else {
            $error->problem = $provider_class::translate_error($message);
        }

        // sets provider plugin name
        $error->pluginname = ($provider_class) ? $provider_class::get_name() : get_config('enrol_ues', 'enrollment_provider');

        return $error;
    }

    /**
     * This method injects itself within the UES interface to unenroll/enroll users by UES section.
     *
     * All users within the given sections will be unenrolled, unless there is a provided callback function.
     * In case of the latter, any given sections meeting given filter criteria will be enrolled.
     * 
     * @param  ues_section[]  $ues_sections
     * @param  function       $inject        an optional callback function to filter sections that should stay enrolled
     * @param  boolean        $silent       [description]
     * @return null
     */
    public static function injectManifest(array $ues_sections, $inject = null, $silent = true) {
        
        // uneroll
        self::unenrollUsersBySections($ues_sections, $silent);

        if ($inject) {
            foreach ($ues_sections as $section) {
                $inject($section);
            }
        }

        self::enrollUsersBySections($ues_sections, $silent);
    }

    /**
     * Unenroll users from the given UES sections, returning any UES errors, if any
     * 
     * Note: this will erase the idnumber of the sections
     *
     * @param  ues_section[]  $ues_sections
     * @param  boolean        $forceSilence
     * @return ues_error[]
     */
    public static function unenrollUsersBySections(array $ues_sections, $forceSilence = true) {
        
        $ues = self::getPlugin();

        // force UES to become silent (if applicable)
        $ues->is_silent = $forceSilence;

        // set the status of each UES section to PENDING
        foreach ($ues_sections as $ues_section) {
            $ues_section->status = self::PENDING;
            $ues_section->save();
        }

        // unenroll users by flushing out all PENDING UES sections
        $ues->handlePendingSectionEnrollment($ues_sections);

        return $ues->errors;
    }

    /**
     * Enroll users into the given UES sections, returning any UES errors, if any
     *
     * Note: this will cause manifestation (course creation if need be)
     * 
     * @param  ues_section[]  $ues_sections
     * @param  boolean        $forceSilence
     * @return ues_error[]
     * 
     * @throws EVENT-UES: ues_section_processed
     */
    public static function enrollUsersBySections($ues_sections, $forceSilence = true) {
        
        $ues = self::getPlugin();

        // force UES to become silent (if applicable)
        $ues->is_silent = $forceSilence;

        // iterate through all given UES sections
        foreach ($ues_sections as $ues_section) {
            
            // iterate through UES user types, setting the status of those users within this UES section to PROCESSED
            foreach (array('teacher', 'student') as $type) {
                $class = 'ues_' . $type;

                $class::reset_status($ues_section, self::PROCESSED);
            }

            // set the status of this UES section to PROCESSED
            $ues_section->status = self::PROCESSED;

            // @EVENT - ues_section_processed
            // handle any potential CPS data manipulation
            $cpsResponse = self::handlePreferences('ues_section_processed', array(
                'ues_section' => $ues_section
            ));

            // get UES section
            $ues_section = $cpsResponse['ues_section'];

            // update the UES section
            $ues_section->save();

            // trigger UES event
            $event = \enrol_ues\event\ues_section_processed::create(array(
                'other' => array (
                    'ues_section_id' => $ues_section->id
                )
            ))->trigger();
        }

        // enroll users by manifesting all with PROCESSED status
        $ues->handleProcessedSectionEnrollment($ues_sections);

        return $ues->errors;
    }

    /**
     * Resets the unenrollment status for each user within the given UES sections
     * 
     * @param  ues_section[]  $ues_sections
     * @param  boolean        $forceSilence
     * @return ues_error[]
     */
    public static function resetUnenrollmentsForSection($ues_sections, $forceSilence = true) {
        
        $ues = self::getPlugin();

        // force UES to become silent (if applicable)
        $ues->is_silent = $forceSilence;

        foreach ($ues_sections as $ues_section) {
            $ues->resetSectionUnenrollments($ues_section);
        }

        return $ues->errors;
    }

    /**
     * Reprocess enrollment by department for a given UES semester
     * 
     * @param  ues_semester  $ues_semester
     * @param  string        $department
     * @param  boolean       $forceSilence
     * @return boolean
     */
    public static function reprocess_department($ues_semester, $department, $forceSilence = true) {
        
        $ues = self::getPlugin();

        // if there are any UESU errors, stop this process
        if ( ! $ues or $ues->errors) {
            return false;
        }

        // the configured UES provider must support department lookups in order to reprocess departments
        if ( ! $ues->provider()->supports_department_lookups()) {
            return false;
        }

        // force UES to become silent (if applicable)
        $ues->is_silent = $forceSilence;

        // @TODO - Work on making department reprocessing code separate
        
        // handle any department-related UES errors
        ues_error::department($ues_semester, $department)->handle($ues);

        $section_ids = ues_section::ids_by_course_department($ues_semester, $department);

        // get all PENDING sections and handle enrollment
        $pending = ues_section::get_all(ues::where('id')->in($section_ids)->status->equal(ues::PENDING));
        $ues->handlePendingSectionEnrollment($pending);
        
        // get all PROCESSED sections and handle enrollment
        $processed = ues_section::get_all(ues::where('id')->in($section_ids)->status->equal(ues::PROCESSED));
        $ues->handleProcessedSectionEnrollment($processed);

        return true;
    }

    /**
     * Reprocess enrollment for a given UES course
     * 
     * @param  ues_course  $ues_course
     * @param  boolean     $forceSilence
     * @return boolean
     */
    public static function reprocess_course($ues_course, $forceSilence = true) {
        
        // get all UES sections of this UES course
        $ues_sections = ues_section::from_course($ues_course, true);

        // handle by section
        return self::reprocess_sections($ues_sections, $forceSilence);
    }

    /**
     * Reprocess enrollment for given UES sections
     * 
     * @param  ues_section[]  $ues_sections
     * @param  boolean        $forceSilence
     * @return boolean
     */
    public static function reprocess_sections($ues_sections, $forceSilence = true) {
        
        $ues = self::getPlugin();

        // if there are any UESU errors, stop this process
        if ( ! $ues or $ues->errors) {
            return false;
        }

        // the configured UES provider must support section lookups in order to reprocess sections
        if ( ! $ues->provider()->supports_section_lookups()) {
            return false;
        }

        // force UES to become silent (if applicable)
        $ues->is_silent = $forceSilence;

        // process enrollment for each given section
        foreach ($ues_sections as $ues_section) {
            $ues->processEnrollment($ues_section->semester(), $ues_section->course(), $ues_section);
        }

        // @TODO - Work on making department reprocessing code separate

        $section_ids = array_keys($ues_sections);

        // get all PENDING sections and handle enrollment
        $pending = ues_section::get_all(ues::where('id')->in($section_ids)->status->equal(ues::PENDING));
        $ues->handlePendingSectionEnrollment($pending);

        // get all PROCESSED sections and handle enrollment
        $processed = ues_section::get_all(ues::where('id')->in($section_ids)->status->equal(ues::PROCESSED));
        $ues->handleProcessedSectionEnrollment($processed);

        return true;
    }

    /**
     * Reprocess enrollment for a given UES teacher
     *
     * If "reverse lookups" are not supported, reprocess by section
     * 
     * @param  ues_teacher  $ues_teacher
     * @param  boolean      $forceSilence
     * @return boolean
     */
    public static function reprocess_for($ues_teacher, $forceSilence = true) {
        
        $ues = self::getPlugin();
        
        // get the configured enrollment provider
        $provider = $ues->provider();

        $ues_user = $ues_teacher->user();

        // if the configured enrollment provider does NOT support reverse lookups, reprocess by section
        if ( ! $provider->supports_reverse_lookups()) {
            return self::reprocess_sections($ues_teacher->sections(), $forceSilence);
        }

        $data_source = $provider->teacher_info_source();

        // get all UES semesters currently in session
        $ues_semesters = ues_semester::in_session();

        // iterate through UES semesters, reprocessing teacher enrollment along the way
        foreach ($ues_semesters as $ues_semester) {
            
            // get all courses for this
            $providedCourses = $data_source->teacher_info($ues_semester, $ues_user);

            // provision each of these pulled courses
            $processed_ues_courses = $ues->convertCourses($providedCourses, $ues_semester);

            // process enrollment for each of these course's sections
            foreach ($processed_ues_courses as $ues_course) {
                foreach ($ues_course->sections as $section) {
                    $ues->processEnrollment($ues_semester, $ues_course, $section);
                }
            }
        }

        $ues->handleEnrollment();
        
        return true;
    }

    /**
     * Handles the deletion of a semester and all of its sections
     * 
     * @param  ues_semester  $ues_semester
     * @param  boolean       $verbose
     * @return null
     * 
     * @throws EVENT-UES: ues_section_dropped
     * @throws EVENT-UES: ues_semester_dropped
     */
    public static function dropSemester($ues_semester, $verbose = false) {
        
        // if enabled, log announcement of semester drop attempt
        $log = function ($msg) use ($verbose) {
            if ($verbose) mtrace($msg);
        };

        $log('Commencing ' . $ues_semester . " drop...\n");

        $sectionsDeleted = 0;
        
        // get all UES sections for this UES semester
        $ues_sections = $ues_semester->sections();

        // iterate through all UES sections
        foreach ($ues_sections as $ues_section) {
            
            // Triggered before db removal and enrollment drop
            
            // trigger UES event
            \enrol_ues\event\ues_section_dropped::create(array(
                'other' => array (
                    'ues_section_id' => $ues_section->id
                )
            ))->trigger();

            // Optimize enrollment deletion
            foreach (array('ues_student', 'ues_teacher') as $ues_user_type) {

                // get the UES class for this user type
                $ues_user_class = 'ues_' . $ues_user_type;

                // delete all UES users of this type from this section
                $ues_user_class::delete_all(array('sectionid' => $ues_section->id));
            }
            
            // delete this UES section
            ues_section::delete($ues_section->id);

            $sectionsDeleted++;

            // log the deletion of sections
            $should_report = ($sectionsDeleted <= 100 and $sectionsDeleted % 10 == 0);
            
            if ($should_report or $sectionsDeleted % 100 == 0) {
                $log("Dropped " . $sectionsDeleted . " sections...\n");
            }

            if ($sectionsDeleted == 100) {
                $log("Reporting 100 sections at a time...\n");
            }
        }

        $log("Dropped all " . $sectionsDeleted . " sections...\n");

        // trigger UES event
        \enrol_ues\event\ues_semester_dropped::create(array(
            'other' => array (
                'ues_semester_id' => $ues_semester->id
            )
        ))->trigger();

        // delete UES semester
        ues_semester::delete($ues_semester->id);

        // log semester deletion
        $log("Dropped semester: " . $ues_semester->id . "...\n");
    }

    /**
     * Returns a formatted string indicating the status of the UES scheduled task
     * 
     * @return string
     */
    public static function getTaskStatusDescription() {

        $scheduled_task = \core\task\manager::get_scheduled_task('\enrol_ues\task\full_process');

        if ($scheduled_task) {

            $disabled = $scheduled_task->get_disabled();
            $last_time = $scheduled_task->get_last_run_time();
            $next_time = $scheduled_task->get_next_scheduled_time();
            $time_format = '%A, %e %B %G, %l:%M %p';

            $details = new stdClass();
            $details->status = ( ! $disabled) ? ues::_s('run_adhoc_status_enabled') : ues::_s('run_adhoc_status_disabled');
            $details->last = ues::_s('run_adhoc_last_run_time', date_format_string($last_time, $time_format, usertimezone()));
            $details->next = ues::_s('run_adhoc_next_run_time', date_format_string($next_time, $time_format, usertimezone()));

            return ues::_s('run_adhoc_scheduled_task_details', $details);
        }

        return '';
    }

    /**
     * Handles UES/CPS data interjection, if any, for the given event name and returns possibly mutated
     *
     * If CPS is not installed on this moodle instance, return the original data
     * 
     * @param  string  $eventName
     * @param  array   $data
     * @return array
     */
    public static function handlePreferences($eventName, $data) {

        global $CFG;

        // if CPS is not installed, take no action and return original data
        if ( ! file_exists($CFG->dirroot . '/blocks/cps/classes/manipulator.php'))
            return $data;

        // require manipulator libs
        require_once $CFG->dirroot . '/blocks/cps/classes/manipulator.php';

        // dispatch and handle the event
        $response = cps_manipulator::handle('enrol_ues', $eventName, $data);

        return $response;
    }
}
