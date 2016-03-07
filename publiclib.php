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
     * Loads all UES extension libraries
     * 
     * @return null
     */
    public static function requireExtensionLibs() {
        $classes = self::base('classes');

        require_once $classes . '/processors.php';
        require_once $classes . '/provider.php';
        require_once $classes . '/guard.php';
    }

    /**
     * Loads all UES exception libraries
     * 
     * @return null
     */
    public static function requireExceptionLibs() {
        $exception = self::base('classes/exceptions');

        require_once $exception . '/UESGuardException.php';
        require_once $exception . '/UESProviderException.php';
    }

    /**
     * Runs the full enrollment process
     * 
     * @return null
     */
    public static function runFullEnrollment($priority = '') {
        
        // require UES libraries
        self::requireLibs();

        try {
            
            // get UES implementation instance of moodle enrol_plugin
            $ues = ues::getPlugin();

            $ues->log('------------------------------------------------');
            $ues->log(' ' . ues::_s('pluginname') . ' has been started');
            $ues->log('------------------------------------------------');
            $ues->log();

            $startTime = microtime();

            // check to make sure UES can be run right now
            ues_guard::check($ues, $priority);
            
            // get the configured enrollment provider
            $provider = $ues->provider();

            // check that the provider meets all requirements
            ues::checkProviderSupportLookups($provider, array(
                'section', 
                'department'
            ));
            
            // set status to running
            $ues->running(true);
            
            // run any provider preprocesses
            $ues->handleProviderPreprocess();

            // provision UES by fetching data from the provider
            $ues->handleProvisioning();

            // manifest moodle enrollment
            $ues->handleEnrollment();

            // @TODO - execute these?
            // $this->email_reports();
            // $this->handle_automatic_errors();
            
            // run any provider postprocesses
            $ues->handleProviderPostprocess();

            $ues->running(false);

            $ues->log('------------------------------------------------');
            $ues->log(' UES Enrollment completed (' . microtime_diff($startTime, microtime()) . ' secs)');
            $ues->log('------------------------------------------------');

        } catch (UESGuardException $e) {
            
            $ues->log('UES Guard error: ' .$e->getMessage());
            $ues->log();
            $ues->running(false);

        } catch (UESProviderException $e) {
            
            $ues->log('UES Provider error: ' .$e->getMessage());
            $ues->log();
            $ues->running(false);

        } catch (UESException $e) {
            
            $ues->log('UES error: ' .$e->getMessage());
            $ues->log();
            $ues->running(false);

        } catch (Exception $e) {
            
            $ues->log('UES fatal error: ' .$e->getMessage());
            $ues->log();
            $ues->running(false);

        }

    }

    /**
     * Returns an instantiated UES implementation of a moodle enrollment plugin
     * 
     * @throws  UESException
     * @return  enrol_ues_plugin
     */
    public static function getPlugin() {
        
        // instantiate UES enrollment plugin
        $ues = enrol_get_plugin('ues');

        if ( ! $ues)
            throw new UESException('Fatal error: Could not load UES enrollment plugin!');

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
     * Returns an array of installed UES provider plugins
     * 
     * @return array ['plugin_name' => 'Plugin name']
     */
    public static function listAvailableProviders() {
        
        // instantiate UES
        $ues = enrol_get_plugin('ues');

        return ( ! $ues) ? array() : $ues->listProviders();
    }


    /**
     * Checks that a specific provider instance supports a given "lookup" method
     * 
     * @param  enrollment_provider  $provider
     * @param  array                $entityKeys         ex: department, section, etc.
     * @param  boolean              $throwsExceptions   will throw exceptions on fail if verbose, otherwise, a boolean
     * @return mixed
     */
    public static function checkProviderSupportLookups($provider = false, $entityKeys = array(), $throwsExceptions = true) {

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
     * Returns base directory path for given directory
     * 
     * @param  string  $dir
     * @return string
     */
    public static function base($dir = '') {
        $path = empty($dir) ? '' : '/' . $dir;

        return dirname(__FILE__) . $path;
    }

    /**
     * Returns localized string for UES package
     * 
     * @param  string  $key
     * @param  string  $a
     * @return string
     */
    public static function _s($key, $a = null) {
        return get_string($key, 'enrol_ues', $a);
    }

    /**
     * Formats a timestamp to YYYY-MM-DD
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

    public static function gen_str($plugin = 'enrol_ues') {
        return function ($key, $a = null) use ($plugin) {
            return get_string($key, $plugin, $a);
        };
    }

    public static function format_string($pattern, $obj) {
        foreach (get_object_vars($obj) as $key => $value) {
            $pattern = preg_replace('/\{' . $key . '\}/', $value, $pattern);
        }

        return $pattern;
    }






    








    

    


    /**
     * Attempts to reprocess all UES errors
     * 
     * @param  array    $errors
     * @param  boolean  $report  whether or not to email a report
     * @return null       
     */
    public static function reprocess_errors($errors, $report = false) {

        $enrol = enrol_get_plugin('ues');

        $amount = count($errors);

        if ($amount) {
            $e_txt = $amount === 1 ? 'error' : 'errors';

            $enrol->log('-------------------------------------');
            $enrol->log('Attempting to reprocess ' . $amount . ' ' . $e_txt . ':');
            $enrol->log('-------------------------------------');
        }

        foreach ($errors as $error) {
            $enrol->log('Executing error code: ' . $error->name);

            if ($error->handle($enrol)) {
                $enrol->handleEnrollment();
                ues_error::delete($error->id);
            }
        }

        if ($report) {
            $enrol->email_reports();
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
        $provider_class = self::provider_class();  // @TODO - this static function has been renamed!!!!

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

    public static function inject_manifest(array $sections, $inject = null, $silent = true) {
        self::unenroll_users($sections, $silent);

        if ($inject) {
            foreach ($sections as $section) {
                $inject($section);
            }
        }

        self::enroll_users($sections, $silent);
    }


    /**
     * Unenroll users from the given sections.
     * Note: this will erase the idnumber of the sections
     *
     * @param ues_sections[] $sections
     * @param boolean $silent
     * @return type
     */
    public static function unenroll_users(array $sections, $silent = true) {
        $enrol = enrol_get_plugin('ues');

        $enrol->is_silent = $silent;

        foreach ($sections as $section) {
            $section->status = self::PENDING;
            $section->save();
        }

        $enrol->handlePendingSectionEnrollment($sections);

        return $enrol->errors;
    }

    // Note: this will cause manifestation (course creation if need be)
    public static function enroll_users(array $sections, $silent = true) {
        $enrol = enrol_get_plugin('ues');

        $enrol->is_silent = $silent;

        foreach ($sections as $section) {
            foreach (array('teacher', 'student') as $type) {
                $class = 'ues_' . $type;

                $class::reset_status($section, self::PROCESSED);
            }

            $section->status = self::PROCESSED;

            // Appropriate events needs to be adhered to
            //events_trigger_legacy('ues_section_process', $section);
            /*
             * Refactor legacy events
             */
            global $CFG;
            if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                $section = cps_ues_handler::ues_section_process($section);
            }

            $section->save();
        }

        $enrol->handle_processed_sections($sections);

        return $enrol->errors;
    }

    public static function reset_unenrollments(array $sections, $silent = true) {
        $enrol = enrol_get_plugin('ues');

        $enrol->is_silent = $silent;

        foreach ($sections as $section) {
            $enrol->reset_unenrollments($section);
        }

        return $enrol->errors;
    }

    public static function reprocess_department($semester, $department, $silent = true) {
        $enrol = enrol_get_plugin('ues');

        if (!$enrol or $enrol->errors) {
            return false;
        }

        if (!$enrol->provider()->supports_department_lookups()) {
            return false;
        }

        $enrol->is_silent = $silent;

        // Work on making department reprocessing code separate
        ues_error::department($semester, $department)->handle($enrol);

        $ids = ues_section::ids_by_course_department($semester, $department);

        $pending = ues_section::get_all(ues::where('id')->in($ids)->status->equal(ues::PENDING));
        $processed = ues_section::get_all(ues::where('id')->in($ids)->status->equal(ues::PROCESSED));

        $enrol->handlePendingSectionEnrollment($pending);
        $enrol->handle_processed_sections($processed);

        return true;
    }

    public static function reprocess_course($course, $silent = true) {
        $sections = ues_section::from_course($course, true);

        return self::reprocess_sections($sections, $silent);
    }

    public static function reprocess_sections($sections, $silent = true) {
        $enrol = enrol_get_plugin('ues');

        if (!$enrol or $enrol->errors) {
            return false;
        }

        if (!$enrol->provider()->supports_section_lookups()) {
            return false;
        }

        $enrol->is_silent = $silent;

        foreach ($sections as $section) {
            $enrol->process_enrollment(
                $section->semester(), $section->course(), $section
            );
        }

        $ids = array_keys($sections);

        $pending = ues_section::get_all(ues::where('id')->in($ids)->status->equal(ues::PENDING));
        $processed = ues_section::get_all(ues::where('id')->in($ids)->status->equal(ues::PROCESSED));

        $enrol->handlePendingSectionEnrollment($pending);
        $enrol->handle_processed_sections($processed);

        return true;
    }

    public static function reprocess_for($teacher, $silent = true) {
        $ues_user = $teacher->user();

        $provider = self::create_provider();  // @TODO - this static function has been renamed!!!

        if ($provider and $provider->supports_reverse_lookups()) {
            $enrol = enrol_get_plugin('ues');

            $info = $provider->teacher_info_source();

            $semesters = ues_semester::in_session();

            foreach ($semesters as $semester) {
                $courses = $info->teacher_info($semester, $ues_user);

                $processed = $enrol->process_courses($semester, $courses);

                foreach ($processed as $course) {

                    foreach ($course->sections as $section) {
                        $enrol->process_enrollment(
                            $semester, $course, $section
                        );
                    }
                }
            }

            $enrol->handleEnrollment();
            return true;
        }

        return self::reprocess_sections($teacher->sections(), $silent);
    }

    public static function drop_semester($semester, $report = false) {
        $log = function ($msg) use ($report) {
            if ($report) mtrace($msg);
        };

        $log('Commencing ' . $semester . " drop...\n");

        $count = 0;
        // Remove data from local tables
        foreach ($semester->sections() as $section) {
            $section_param = array('sectionid' => $section->id);

            $types = array('ues_student', 'ues_teacher');

            // Triggered before db removal and enrollment drop
            //events_trigger_legacy('ues_section_drop', $section);
            /*
             * Refactor legacy events call
             */
            global $CFG;
            if(file_exists($CFG->dirroot.'/blocks/ues_logs/eventslib.php')){
                ues_logs_event_handler::ues_section_drop($section);
            }
            if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                cps_ues_handler::ues_section_drop($section);
            }
            if(file_exists($CFG->dirroot.'/blocks/post_grades/events.php')){
                post_grades_handler::ues_section_drop($section);
            }

            // Optimize enrollment deletion
            foreach ($types as $class) {
                $class::delete_all(array('sectionid' => $section->id));
            }
            ues_section::delete($section->id);

            $count ++;

            $should_report = ($count <= 100 and $count % 10 == 0);
            if ($should_report or $count % 100 == 0) {
                $log('Dropped ' . $count . " sections...\n");
            }

            if ($count == 100) {
                $log("Reporting 100 sections at a time...\n");
            }
        }

        $log('Dropped all ' . $count . " sections...\n");

        //events_trigger_legacy('ues_semester_drop', $semester);
        /*
         * Refactor legacy events.
         */
        global $CFG;
        if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
            cps_ues_handler::ues_section_drop($semester);
        }
        if(file_exists($CFG->dirroot.'/blocks/post_grades/events.php')){
            post_grades_handler::ues_section_drop($semester);
        }

        ues_semester::delete($semester->id);

        $log('Done');
    }

    public static function get_task_status_description() {

        $scheduled_task = \core\task\manager::get_scheduled_task('\enrol_ues\task\full_process');

        if ($scheduled_task) {

            $disabled = $scheduled_task->get_disabled();
            $last_time = $scheduled_task->get_last_run_time();
            $next_time = $scheduled_task->get_next_scheduled_time();
            $time_format = '%A, %e %B %G, %l:%M %p';

            $details = new stdClass();
            $details->status = (!$disabled) ? ues::_s('run_adhoc_status_enabled') : ues::_s('run_adhoc_status_disabled');
            $details->last = ues::_s('run_adhoc_last_run_time', date_format_string($last_time, $time_format, usertimezone()));
            $details->next = ues::_s('run_adhoc_next_run_time', date_format_string($next_time, $time_format, usertimezone()));

            return ues::_s('run_adhoc_scheduled_task_details', $details);
        }

        return false;
    }
}
