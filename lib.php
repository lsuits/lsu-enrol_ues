<?php

/**
 * @package enrol_ues
 */
defined('MOODLE_INTERNAL') or die();

require_once dirname(__FILE__) . '/publiclib.php';

class enrol_ues_plugin extends enrol_plugin {

    /**
     * Error log container
     *
     * @var array
     */
    private $errorLog = array();

    /**
     * Message log container
     *
     * @var array
     */
    private $messageLog = array();

    /**
     * admin config setting
     *
     * @var bool
     */
    private $is_silent = false;

    /**
     * an instance of the ues enrollment provider.
     *
     * Provider is configured in admin settings.
     *
     * @var enrollment_provider $_provider
     */
    private $_provider;

    /**
     * Constuct a UES enrolment plugin instance.
     * 
     * Require internal and external libs
     *
     * @global object $CFG
     */
    public function __construct() {
        global $CFG;

        ues::requireDaoLibs();
        ues::requireExceptionLibs();
        require_once $CFG->dirroot . '/group/lib.php';
        require_once $CFG->dirroot . '/course/lib.php';
    }

    /**
     * Getter for $_provider.
     * 
     * @param  boolean  $autoInit  if $_provider is not set already, this option will automatically initialize it
     * @return enrollment_provider
     */
    public function provider($autoInit = true) {

        if ( ! $this->isProviderLoaded()) {
            
            if ( ! $autoInit)
                return false;

            try {
                $this->_provider = $this->loadSelectedProvider();
            } catch (Exception $e) {
                return false;
            }
        }

        return $this->_provider;
    }

    /**
     * Returns the status of whether or not an enrollment provider has been loaded for this UES enrollment instance
     * 
     * @return boolean
     */
    private function isProviderLoaded() {

        return (empty($this->_provider)) ? false : true;
    }

    /**
     * Attempts to initialize the selected enrollment provider.
     * 
     * Checks if provider supports either section or department lookups.
     *
     * @return enrollment_provider
     * @throws UESProviderException
     */
    private function loadSelectedProvider() {
        
        $providerKey = $this->config('enrollment_provider');

        if ( ! $providerKey) {
            throw new UESProviderException('Provider not selected in UES config.');
        }

        if ( ! $this->isProviderInstalled($providerKey)) {
            throw new UESProviderException('Could not verify that the currently selected provider is installed.');
        }
        
        // require UES libs
        ues::requireLibs();
        
        // instantiate new provider
        $provider = $this->newProvider($providerKey);

        if ( ! $provider)
            throw new UESProviderException('Could not instantiate the selected enrollment provider.');

        return $provider;
    }

    /**
     * Returns whether or not a specific UES enrollment provider is still installed in this Moodle instance
     * 
     * @param  string   $providerKey  plugin_name
     * @return boolean
     */
    private function isProviderInstalled($providerKey) {

        // get all installed providers
        $providers = $this->listProviders();

        // if this provider key is not in the array then it is no longer installed
        return ( ! isset($providers[$providerKey])) ? false : true;

    }

    /**
     * Instantiate a new UES enrollment provider
     * 
     * @param  string  $providerKey  plugin name
     * @return enrollment_provider
     */
    private function newProvider($providerKey) {
        
        // load provider libs
        global $CFG;
        $basedir = $CFG->dirroot . '/local/' . $providerKey;
        $providerPluginFile = $basedir . '/plugin.php';
        
        if ( ! file_exists($providerPluginFile))
            return false;

        require_once $providerPluginFile;
        
        $enrollmentPlugin = $providerKey . '_enrollment_plugin';
        $load = 'ues_load_' . $providerKey . '_provider';
        $enrollmentPlugin::$load();

        $providerClass = $providerKey . '_enrollment_provider';

        if ( ! $providerClass) {
            return false;
        }

        return new $providerClass();
    }

    /**
     * Returns an array of installed UES provider plugins
     * 
     * @return array ['plugin_name' => 'Plugin name']
     */
    public function listProviders() {

        global $CFG;
        
        $basedir = $CFG->dirroot . '/local';

        $providers = array();
        
        // scan 'local' directory for UES providers and add valid providers to array
        foreach(scandir($basedir) as $file) {
            if(file_exists($basedir . DIRECTORY_SEPARATOR . $file . DIRECTORY_SEPARATOR . 'provider.php')){
                require_once $basedir . DIRECTORY_SEPARATOR . $file . DIRECTORY_SEPARATOR . 'plugin.php';
                $class = $file . '_enrollment_plugin';
                $providers += $class::ues_list_provider(); // @TODO - refactor this
            }
        }

        return ( ! empty($providers)) ? $providers : false;
    }

    /**
     * Setter for running status
     * 
     * In addition, logs the output of command.
     * 
     * @param  boolean  $setStatus  whether or not UES should be running
     * @return null
     */
    public function running($setStatus) {
        $this->config('running', $setStatus, ! $setStatus);

        $provider = $this->provider();

        $this->log(($setStatus) ? 'Connecting to: ' . $provider->get_name() : 'Disconnected from: ' . $provider->get_name());
        $this->log();
    }

    /**
     * Getter for running status
     * 
     * @return boolean
     */
    public function isRunning() {
        return $this->config('running');
    }

    /**
     * Handle this provider's preprocesses, if any
     * 
     * @param  verbose  $verbose            if enabled, this will log the commands output
     * @param  boolean  $throwsExceptions   will throw exceptions on fail if verbose, otherwise, a boolean
     * @return mixed
     * @throws UESProviderException
     */
    public function handleProviderPreprocess($verbose = true, $throwsExceptions = true) {

        if ($verbose)
            $this->log('Running all provider preprocesses...');

        if ( ! $this->provider()->preprocess($this)) {
            if ($throwsExceptions) {
                throw new UESProviderException('An error occurred during the preprocess.');
            } else {
                return false;
            }
        }

        if ($verbose) {
            $this->log('Preprocesses complete.');
            $this->log();
        }
    }

    /**
     * Handle this provider's preprocesses, if any
     * 
     * @param  verbose  $verbose            if enabled, this will log the commands output
     * @param  boolean  $throwsExceptions   will throw exceptions on fail if verbose, otherwise, a boolean
     * @return mixed
     * @throws UESProviderException
     */
    public function handleProviderPostprocess($verbose = true, $throwsExceptions = true) {

        if ($verbose)
            $this->log('Running all provider postprocesses...');

        if ( ! $this->provider()->postprocess($this)) {
            if ($throwsExceptions) {
                throw new UESProviderException('An error occurred during the postprocess.');
            } else {
                return false;
            }
        }

        if ($verbose) {
            $this->log('Postprocesses complete.');
            $this->log();
        }
    }

    /**
     * Fetches filtered UES semesters and process enrollment for each.
     * 
     * @return null
     */
    public function handleProvisioning() {

        $this->log('Begin provisioning...');
        $this->log();

        $now = time();

        // get all semesters that are considered valid right now
        $ues_semesters = $this->convertSemesterData($now);

        // if we have valid semesters, continue with provisioning
        if (count($ues_semesters)) {
            
            $this->log(' => Found ' . count($ues_semesters) . ' ' . $this->pluralize(count($ues_semesters), 'semester') . ' to be provisioned.');
            $this->log('');

            // provision each semester
            foreach ($ues_semesters as $ues_semester) {
                $this->log('Fetching courses/sections for ' . $ues_semester . '...');

                $this->provisionUesSemester($ues_semester);
            }
        } else {
            $this->log('Could not find any semesters to provision.');
        }
    }

    /**
     * Fetches semesters from the enrollment provider, convert to ues_semesters, persist, and return filtered collection
     * 
     * @param  int  $time  timestamp
     * @return ues_semester[]
     *
     */
    private function convertSemesterData($time) {

        $formattedDate = $this->getFormattedSemesterSubDaysTime($time);

        $this->log('Fetching all semesters since ' . $formattedDate . ' from provider...');

        try {
            // gets semester data source
            $semester_source = $this->provider()->semester_source();
            
            // fetch semesters from data source
            $semesters = $semester_source->semesters($formattedDate);

            $this->log(' => Found ' . count($semesters) . ' ' . $this->pluralize(count($semesters), 'semester') . ' total.');
            
            // convert fetched semesters into persisted ues_semester collection
            $ues_semesters = $this->convertProvidedSemesters($semesters);

            // filter out invalid, ignored, or out-of-date-range semesters
            $filteredSemesters = $this->filterUesSemesters($ues_semesters, $time);

            return $filteredSemesters;

        } catch (Exception $e) {

            $this->logError($e->getMessage());
            return array();
        }
    }

    /**
     * Gets calculated date threshold from set configuration formatted as a complete date
     * 
     * @param  timestamp  $time
     * @return string
     */
    private function getFormattedSemesterSubDaysTime($time) {

        // calculate date threshold for semester query
        $sub_days = $this->calculateSubDaysTime();

        $formattedDate = ues::format_time($time - $sub_days);

        return $formattedDate;
    }

    /**
     * Returns the UES sub_days config as an integer
     * 
     * @return int
     */
    private function calculateSubDaysTime() {
        $set_days = (int) $this->config('sub_days');
        $sub_days = 24 * $set_days * 60 * 60;

        return $sub_days;
    }

    /**
     * Receives semesters provided from the provider data source and converts and persists them as ues_semesters
     * 
     * @param  stdClass[year, name, campus, session_key, classes_start] $providedSemesters
     * @return ues_semester[]
     *
     * @throws EVENT-UES: ues_semester_process
     */
    private function convertProvidedSemesters($providedSemesters) {
        
        $ues_semesters = array();

        foreach ($providedSemesters as $providedSemester) {
            try {
                $params = array(
                    'year'        => $providedSemester->year,
                    'name'        => $providedSemester->name,
                    'campus'      => $providedSemester->campus,
                    'session_key' => $providedSemester->session_key
                );

                // convert this semester to a ues_semester
                $ues_semester = ues_semester::upgrade_and_get($providedSemester, $params);

                // if classes have not yet begun, do not process any further
                if (empty($ues_semester->classes_start)) {
                    continue;
                }

                // Call event before potential insert, as to notify creation
                // events_trigger_legacy('ues_semester_process', $ues_semester);
                // @EVENT - ues_semester_process (unmonitored)

                // persist to {ues_semesters}
                $ues_semester->save();

                // Fill in metadata from {enrol_ues_semestermeta}
                $ues_semester->fill_meta();

                $ues_semesters[] = $ues_semester;

            } catch (Exception $e) {
                $this->logError($e->getMessage());
            }
        }

        return $ues_semesters;
    }

    /**
     * Returns a filtered ues_semester collection based on: validity, ignore config, and date
     * 
     * @param  ues_semesters[]  $ues_semesters
     * @param  int              $time           timestamp
     * @return ues_semesters[]  $ues_semesters
     */
    private function filterUesSemesters($ues_semesters, $time) {
        
        // filter out from ues_semester collection any semester without an end date
        list($passedSemesters, $failedSemesters) = $this->partition($ues_semesters, function($s) {
            return ! empty($s->grades_due);
        });

        // log notice of failed semester
        foreach ($failedSemesters as $failedSemester) {
            $this->logError(ues::_s('failed_sem', $failedSemester));
        }

        // filter out from remaining ues_semesters any semester that is set to be ignored
        list($ignoredSemesters, $validSemesters) = $this->partition($passedSemesters, function($s) {
            return ! empty($s->semester_ignore);
        });

        // update status of all ignored semesters from 'manifested' to 'pending' so that they may be unenrolled
        foreach ($ignoredSemesters as $ignoredSemester) {
            
            $where_manifested = ues::where()
                ->semesterid->equal($ignoredSemester->id)
                ->status->equal(ues::MANIFESTED);

            $to_drop = array('status' => ues::PENDING);

            // @TODO - does this make sense? marking them to pending status may send them back through the process?
            ues_section::update($to_drop, $where_manifested);
        }

        $sub_days = $this->calculateSubDaysTime();

        // filter out any semester outside of the appropriate date range as follows:
        // semester's start date (and configured "sub_day" extension) must be less than the originally specified query date
        // semester's end date (when grades are due) must be greated than the originally specified query date
        $filteredSemesters = array_filter($validSemesters, function ($sem) use ($time, $sub_days) {
            $end_check = $time < $sem->grades_due;

            return ($sem->classes_start - $sub_days) < $time && $end_check;
        });

        return $filteredSemesters;
    }

    /**
     * Iterates through an array of objects and filters into pass/fail arrays using a given anonymous function
     * 
     * @param  array     $collection  an array of objects
     * @param  callable  $func        an anonymous function
     * @return array
     */
    private function partition($collection, $func) {
        $pass = array();
        $fail = array();

        foreach ($collection as $key => $single) {
            if ($func($single)) {
                $pass[$key] = $single;
            } else {
                $fail[$key] = $single;
            }
        }

        return array($pass, $fail);
    }

    /**
     * Provision a UES semester with data from a provider depending on set configuration
     * 
     * Note: a semester may be processed by departments or by sections (one must be selected)
     * 
     * @param  ues_semester  $ues_semester
     * @return null
     */
    private function provisionUesSemester($ues_semester) {

        // fetch courses within this semester
        $ues_courses = $this->convertCourseData($ues_semester);

        // if no courses within this semester, skip it
        if (empty($ues_courses)) {
            return;
        }

        // determine how to process this semester (if not by department, then by section)
        $setByDepartment = (bool) $this->config('process_by_department');

        // process this semester by DEPARTMENT if ues config option enabled AND this provider supports this method
        if ($setByDepartment and $this->provider()->supports_department_lookups()) {

            $this->processSemesterByDepartment($ues_semester, $ues_courses);

        // otherwise, process this semester by SECTION if this provider supports this method
        } else if ( ! $setByDepartment and $this->provider()->supports_section_lookups()) {

            $this->processSemesterBySection($ues_semester, $ues_courses);

        // otherwise, do not process this semester and log the error
        } else{

            $message = ues::_s('could_not_enroll_semester', $ues_semester);

            $this->logError($message);
        }
    }

    /**
     * Fetches courses from the enrollment provider, convert to ues_courses, persist, and return filtered collection
     * 
     * @param  ues_semester  $ues_semester
     * @return ues_course[]
     *
     */
    public function convertCourseData($ues_semester) {
        
        try {

            // gets course data source
            $course_source = $this->provider()->course_source();

            // fetch this semester's courses from data source
            $courses = $course_source->courses($ues_semester);

            $this->log(' => Found ' . count($courses) . ' ' . $this->pluralize(count($courses), 'course') . '.');
            $this->log();

            // convert fetched courses into persisted ues_course collection
            $ues_courses = $this->convertCourses($courses, $ues_semester);

            return $ues_courses;

        } catch (Exception $e) {
            
            $this->logError(sprintf('Unable to process courses for %s; Message was: %s', $ues_semester, $e->getMessage()));

            // Queue up errors
            ues_error::courses($ues_semester)->save();

            return array();
        }
    }

    /**
     * Receives a UES semester's courses from the data source and persists them and their sections as ues_courses and ues_sections, respectively
     * 
     * @param  stdClass[]    $providedCourses
     * @param  ues_semester  $ues_semester
     * @return ues_course[]
     *
     * @throws EVENT-UES: ues_course_process
     */
    public function convertCourses($providedCourses, $ues_semester) {
        
        $ues_courses = array();

        foreach ($providedCourses as $providedCourse) {
            
            try {
                
                $params = array(
                    'department' => $providedCourse->department,
                    'cou_number' => $providedCourse->cou_number
                );

                $ues_course = ues_course::upgrade_and_get($providedCourse, $params);

                // Call event before potential insert, as to notify creation
                // events_trigger_legacy('ues_course_process', $ues_course);
                // @EVENT - ues_course_process (unmonitored)

                $ues_course->save();

                // get this course's current sections
                $currentSections = $ues_course->sections;

                // convert this course's sections to persisted ues_section collection
                $ues_sections = $this->convertCourseSections($currentSections, $ues_course, $ues_semester);

                // update this course's sections attribute to the ues_sections
                $ues_course->sections = $ues_sections;

                $ues_courses[] = $ues_course;

            } catch (Exception $e) {
                $this->logError($e->getMessage());
            }
        }

        return $ues_courses;
    }

    /**
     * Receives a UES course and semester and then converts and persists it's children sections to ues_sections
     * 
     * @param  ues_section[]  $currentSections
     * @param  ues_course     $ues_course
     * @param  ues_semester   $ues_semester
     * @return ues_section[]
     */
    public function convertCourseSections($currentSections, $ues_course, $ues_semester) {

        $ues_sections = array();
                
        foreach ($currentSections as $section) {
            
            $params = array(
                'courseid'   => $ues_course->id,
                'semesterid' => $ues_semester->id,
                'sec_number' => $section->sec_number
            );

            // convert this section to a ues_section
            $ues_section = ues_section::upgrade_and_get($section, $params);

            // if this section does not exist, create and mark as PENDING
            if (empty($ues_section->id)) {
                $ues_section->courseid = $ues_course->id;
                $ues_section->semesterid = $ues_semester->id;
                $ues_section->status = ues::PENDING;

                $ues_section->save();
            }

            $ues_sections[] = $ues_section;
        }

        return $ues_sections;
    }

    /**
     * Process a semester by department
     * 
     * @param ues_semester  $ues_semester
     * @param ues_course[]  $ues_courses  NB: must have department attribute set
     */
    private function processSemesterByDepartment($ues_semester, $ues_courses) {
        
        // construct array of departments and its children course ids
        $departments = ues_course::flatten_departments($ues_courses);

        // iterate through each department, fetch its course's sections, and run enrollment for each
        foreach ($departments as $department => $courseids) {
            $filters = ues::where()
                ->semesterid->equal($ues_semester->id)
                ->courseid->in($courseids);

            // get all 'current' sections (already existing in the DB)
            $ues_sections = ues_section::get_all($filters);

            $this->processEnrollmentByDepartment($ues_semester, $department, $ues_sections);
        }
    }

    /**
     * Workhorse method that brings enrollment data from the provider together with existing records
     * and then dispatches sub processes that operate on the differences between the two.
     *
     * @param ues_semester  $ues_semester  semester to process
     * @param string        $department    department to process
     * @param ues_section[] $ues_sections  current UES records for the department/semester combination
     */
    public function processEnrollmentByDepartment($ues_semester, $department, $ues_sections) {
        
        try {

            // fetch all current UES section ids from within this semester and department
            $sectionids = ues_section::ids_by_course_department($ues_semester, $department);

            // process enrollment for given user types
            foreach (array('teacher', 'student') as $user_type) {
                $this->processUserEnrollmentByDepartment($user_type, $ues_semester, $department, $sectionids);
            }

            // fetch all current UES sections from within this semester and department
            $ids_param = ues::where('id')->in($sectionids);
            $current_ues_sections = ues_section::get_all($ids_param);

            // process enrollment for each section and remove from list of 'current' sections
            foreach ($ues_sections as $ues_section) {
                
                $ues_course = $ues_section->course();
                
                $this->processSectionEnrollment($ues_semester, $ues_course, $ues_section);

                unset($current_ues_sections[$ues_section->id]);
            }

            // set the status of any remaining sections to PENDING so that they may be dropped
            if ( ! empty($current_ues_sections)) {
                ues_section::update(
                    array('status' => ues::PENDING),
                    ues::where('id')->in(array_keys($current_ues_sections))
                );
            }

        } catch (Exception $e) {

            $message = sprintf(
                    "Message: %s\nFile: %s\nLine: %s\nTRACE:\n%s\n",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
            );

            $this->logError(sprintf('Failed to process %s:\n%s', '$ues_semester $department', $message));

            // save UES error for handling later
            ues_error::department($ues_semester, $department)->save();
        }
    }



    /**
     * Process a semester by section
     * 
     * @param ues_semester  $ues_semester
     * @param ues_course[]  $ues_courses  NB: must have department attribute set
     *
     */
    private function processSemesterBySection($semester, $courses) {
        
        foreach ($courses as $course) {
            
            foreach ($course->sections as $section) {
                $ues_section = ues_section::by_id($section->id);
                $this->processEnrollment(
                    $semester, $course, $ues_section
                );
            }
        }
    }

    /**
     * Could be used to process a single course upon request
     */
    public function processEnrollment($ues_semester, $ues_course, $ues_section) {

        try {

            // process enrollment for given user types
            foreach (array('teacher', 'student') as $user_type) {
                $this->processUserEnrollmentBySection($user_type, $ues_semester, $ues_course, $ues_section);
            }

            $this->processSectionEnrollment($ues_semester, $ues_course, $ues_section);

        } catch (Exception $e) {
            $this->logError($e->getMessage());

            ues_error::section($ues_section)->save();
        }
    }

    /**
     * Fetches users of a specified type from the data source and processes user enrollment for a given section
     * 
     * @param  string        $user_type     teacher|student
     * @param  ues_semester  $ues_semester
     * @param  ues_course    $ues_course
     * @param  ues_section   $ues_section
     * @return null
     */
    private function processUserEnrollmentBySection($user_type, $ues_semester, $ues_course, $ues_section) {

        // get user_type data source from the provider
        $type_src_fn = $user_type . '_source';
        $dataSource = $this->provider()->$type_src_fn();

        // fetch all users of this user_type from within this semester and section
        $users_types_fn = $user_type . 's';
        $providedUsers = $dataSource->$users_types_fn($ues_semester, $ues_course, $ues_section);

        $filter = array('sectionid' => $ues_section->id);
        $ues_user_type_class = 'ues_' . $user_type;
        $current_ues_users = $ues_user_type_class::get_all($filter);

        // process enrollment for the provided users, returning a list of "remaining" UES users in the process
        // @NOTE - passing by reference was removed here
        // $this->process_teachers($ues_section, $providedUsers, $current_ues_users);
        // changed to:
        $remaining_ues_users = $this->processUsersByType($user_type, $ues_section, $providedUsers, $current_ues_users);
        
        // if we have some remaining users, release them now
        if (count($remaining_ues_users)) {
            $this->releaseUsers($user_type, $remaining_ues_users);
        }
    }

    /**
     * Fetches users of a specified type from the data source and processes enrollment by a semester's department's UES section ids
     * 
     * @param  string        $user_type        teacher|student
     * @param  ues_semester  $ues_semester
     * @param  string        $department       department to process
     * @param  array         $ues_section_ids
     * @return null
     */
    private function processUserEnrollmentByDepartment($user_type, $ues_semester, $department, $ues_section_ids) {

        // get user_type data source from the provider
        $type_src_fn = $user_type . '_department_source';
        $dataSource = $this->provider()->$type_src_fn();

        // fetch all users of this user_type from within this semester and department
        $users_type_fn = $user_type . 's';
        $users = $dataSource->$users_type_fn($ues_semester, $department);

        // fetch all current UES users of this user_type from this department's semester's sections
        $filter = ues::where('sectionid')->in($ues_section_ids);
        $ues_user_type_class = 'ues_' . $user_type;
        $ues_users = $ues_user_type_class::get_all($filter);

        // process enrollment for these users within this department
        $this->fillUserRolesByDepartment($user_type, $ues_semester, $department, $users, $ues_users);
    }

    /**
     * Process a section's user enrollment
     *
     * This method requires that there be at least one processable teacher in this section
     * 
     * @param  ues_semester  $ues_semester
     * @param  ues_course    $ues_course
     * @param  ues_section   $ues_section
     * @return null
     *
     * @throws EVENT-UES: ues_section_process
     */
    private function processSectionEnrollment($ues_semester, $ues_course, $ues_section) {
        
        // get count of all UES teachers from this section that have already been processed
        $processed_filter = ues::where()
            ->status->in(ues::PROCESSED, ues::ENROLLED)
            ->sectionid->equal($ues_section->id);

        $processedTeacherCount = ues_teacher::count($processed_filter);

        // if this section has any teachers, allow it to be processed
        if ( ! empty($processedTeacherCount)) {
            
            // remember section's previous status for later
            $sectionPreviousStatus = $ues_section->status;

            // update section attributes
            $ues_section->semester = $ues_semester;
            $ues_section->course = $ues_course;

            // determine whether or not users of a given type will be enrolled in this section
            $enrollmentWillOccur = false;

            foreach (array('teacher', 'student') as $user_type) {
                if ($this->countProcessedUsersInSection($user_type, $ues_section));
                    $enrollmentWillOccur = true;
            }

            // if there are any processed users of given types within this section, allow it's status to be updated to PROCESSED
            if ($enrollmentWillOccur) {
                
                // update statuses of all teacher's within this section from ENROLLED to PROCESSED to make sure all teachers will be enrolled
                ues_teacher::reset_status($ues_section, ues::PROCESSED, ues::ENROLLED);
                
                // update the status of this section to PROCESSED
                $ues_section->status = ues::PROCESSED;
            }

            // Allow outside interaction
            // events_trigger_legacy('ues_section_process', $ues_section);
            // @EVENT - ues_section_process
            /**
             * Refactor old events_trigger_legacy
             */
            global $CFG;
            if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                require_once $CFG->dirroot.'/blocks/cps/events/ues.php';
                $ues_section = cps_ues_handler::ues_section_process($ues_section);
            }

            // if this section's status has changed, save all changes
            if ($sectionPreviousStatus != $ues_section->status) {
                $ues_section->save();
            }
        }
    }

    /**
     * Returns the count of processed users of a given user type within a given section
     * 
     * @param  string       $user_type  teacher|student
     * @param  ues_section  $ues_section
     * @return int
     */
    private function countProcessedUsersInSection($user_type, $ues_section) {

        $enrollment_filter = ues::where()
            ->sectionid->equal($ues_section->id)
            ->status->in(ues::PROCESSED, ues::PENDING);

        $ues_user_class = 'ues_' . $user_type;

        $count = $ues_user_class::count($enrollment_filter);

        return $count;
    }

    /**
     * Fills roles
     * 
     * @param  string                         $user_type          teacher|student
     * @param  ues_semester                   $ues_semester
     * @param  string                         $department
     * @param  object[]                       $providedUsers      incoming users from the provider
     * @param  ues_teacher[] | ues_student[]  $current_ues_users  all UES users for this semester
     */
    private function fillUserRolesByDepartment($user_type, $ues_semester, $department, $providedUsers, $current_ues_users) {
        
        $remaining_ues_users = array();

        foreach ($providedUsers as $providedUser) {
            
            // find the UES course from the given department and the pulled user's course number
            $course_params = array(
                'department' => $department,
                'cou_number' => $providedUser->cou_number
            );

            $ues_course = ues_course::get($course_params);

            // if a course does not exist, proceed to the next user
            if (empty($ues_course)) {
                continue;
            }

            // find the UES section from this UES course for the given department and the pulled user's section number
            $section_params = array(
                'semesterid' => $ues_semester->id,
                'courseid'   => $ues_course->id,
                'sec_number' => $providedUser->sec_number
            );

            $ues_section = ues_section::get($section_params);

            // if a section does not exist, proceed to the next user
            if (empty($ues_section)) {
                continue;
            }
            
            // process enrollment for the provided users, returning a list of "remaining" UES users in the process
            // @NOTE - passing by reference was removed here
            // $this->{'process_'.$user_type.'s'}($ues_section, array($user), $current_ues_users);  <--- OLD
            $remaining_ues_users = $this->processUsersByType($user_type, $ues_section, array($providedUser), $current_ues_users);
        }

        // release any remaining users
        if (count($remaining_ues_users)) {
            $this->releaseUsers($user_type, $remaining_ues_users);
        }
    }

    /**
     * Releases users by setting their status to UNEROLLED or PENDING base on their status
     * 
     * @param  string                         $user_type  teacher|student
     * @param  ues_teacher[] | ues_student[]  $ues_users
     * @return null
     *
     * @throws EVENT-UES: ues_teacher_release
     * @throws EVENT-UES: ues_student_release
     */
    private function releaseUsers($user_type, $ues_users) {

        foreach ($ues_users as $ues_user) {
            
            // No reason to release a second time
            if ($ues_user->status == ues::UNENROLLED) {
                continue;
            }

            // Maybe the course hasn't been created... clear the pending flag
            $status = ($ues_user->status == ues::PENDING) ? ues::UNENROLLED : ues::PENDING;

            $ues_user->status = $status;
            $ues_user->save();

            // Drop manifested sections for teacher POTENTIAL drops
            if ($ues_user->status == ues::PENDING and $user_type == 'teacher') {
                
                $existing = ues_teacher::get_all(ues::where()
                    ->status->in(ues::PROCESSED, ues::ENROLLED)
                );

                // No other primary, so we can safely flip the switch
                if (empty($existing)) {
                    ues_section::update(
                        array('status' => ues::PENDING),
                        array(
                            'status' => ues::MANIFESTED,
                            'id' => $ues_user->sectionid
                        )
                    );
                }
            }

            global $CFG;
            
            if ($user_type === 'teacher') {
                
                if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                    require_once $CFG->dirroot.'/blocks/cps/events/ues.php';

                    // Specific release for instructor
                    //events_trigger_legacy('ues_teacher_release', $ues_user);
                    // @EVENT - ues_teacher_release
                    // $ues_user = cps_ues_handler::ues_teacher_release($ues_user);
                }

            } else if($user_type === 'student') {

                // trigger UES event
                $event = \enrol_ues\event\ues_student_released::create(array(
                    'other' => array (
                        'ues_user_id' => $ues_user->id
                    )
                ))->trigger();

            }
        }
    }

    /**
     * Dispatch method for processing (enrolling) a specific type of provided users into a specific section
     *
     * Returns an array of UES users that were not enrolled during this process, if given
     *
     * @param  string                         $user_type   (student|teacher)
     * @param  ues_section                    $ues_section
     * @param  object[]                       $providedUsers
     * @param  ues_student[] | ues_teacher[]  $current_ues_users
     * @return ues_student[] | ues_teacher[]  $remaining_ues_users
     */
    private function processUsersByType($user_type, $ues_section, $providedUsers, $current_ues_users = array()) {

        switch ($user_type) {
            
            case 'teacher':
                
                // @NOTE - passing by reference was removed from this
                $remaining_ues_users = $this->addUsersToSectionFromList('teacher', $ues_section, $providedUsers, $current_ues_users, function($user) {
                    return array('primary_flag' => $user->primary_flag);
                });
                
                break;
            
            case 'student':

                // @NOTE - passing by reference was removed from this
                $remaining_ues_users = $this->addUsersToSectionFromList('student', $ues_section, $providedUsers, $current_ues_users);

                break;

            default:
                $remaining_ues_users = array();

                break;
        }

        return $remaining_ues_users;
    }

    /**
     * Recieves a list of provided users objects to be enrolled in this section and enrolls them, returning an array of "remaining" UES users, if given
     * 
     * This method will insure that all UES user's (and moodle users) are created or updated
     * 
     * In addition, this method will accept an optional array of UES users (ues_teacher|ues_student) and remove
     * user's from that list by id as enrollment is taking place.
     * 
     * In addition, this method may recieve an option callback function to help with default data assignment
     *
     * @param  string                         $user_type          teacher|student
     * @param  ues_section                    $ues_section
     * @param  object[]                       $providedUsers
     * @param  ues_teacher[] | ues_student[]  $current_ues_users  all users currently registered in the UES tables for this section
     * @param  callback                       $extra_params       function returning additional user parameters/fields
     * an associative array of additional params, given a user as input
     * @return ues_teacher[] | ues_student[]  $remaining_ues_users
     *
     * @throws EVENT-UES: ues_student_process
     * @throws EVENT-UES: ues_teacher_process
     */
    private function addUsersToSectionFromList($user_type, $ues_section, $providedUsers, $current_ues_users = array(), $extra_params = null) {
        
        $remaining_ues_users = $current_ues_users;

        $ues_user_class = 'ues_' . $user_type;
        
        $already_enrolled = array(ues::ENROLLED, ues::PROCESSED);

        foreach ($providedUsers as $user) {
            
            // create user
            $new_ues_user = $this->createUser($user);

            // set lookup params
            $params = array(
                'sectionid' => $ues_section->id,
                'userid'    => $new_ues_user->id
            );

            // add any additional params
            if ( ! is_null($extra_params)) {
                $params += $extra_params($new_ues_user);
            }

            // instantiate this user as it's ues type (ues_teacher|ues_student)
            $ues_user = $ues_user_class::upgrade($new_ues_user);

            unset($ues_user->id);
            
            // if there already exists a UES user of this type within the same section (and any additional criteria)
            if ($persisted_ues_type = $ues_user_class::get($params, true)) {
                
                // update the new instantiation's id to the persisted ues user type's id
                $ues_user->id = $persisted_ues_type->id;
                
                // if a list of current users was provided, remove this user by id
                if ( is_array($current_ues_users))
                    unset($remaining_ues_users[$persisted_ues_type->id]);

                // Intentionally save meta fields before continuing
                // Meta fields can change without enrollment changes
                $fields = get_object_vars($ues_user);
                if ($ues_user->params_contains_meta($fields)) {
                    $ues_user->save();
                }

                // if this user is already enrolled in this section, move to next provided user
                if (in_array($persisted_ues_type->status, $already_enrolled)) {
                    continue;
                }
            }

            // assign this UES user type its moodle user id and given section criteria
            $ues_user->userid = $new_ues_user->id;
            $ues_user->sectionid = $ues_section->id;

            // update this user's status to PROCESSED
            $ues_user->status = ues::PROCESSED;

            // persist the UES user type
            $ues_user->save();

            // if this was a "freshly" processed user - OR - the old user was marked as UNENROLLED
            if (empty($persisted_ues_type) or $persisted_ues_type->status == ues::UNENROLLED) {
                
                // fire an event based on this UES user's type (student|teacher)
                switch ($ues_user_class) {
                    
                    case 'student':
                        
                        // trigger UES event
                        $event = \enrol_ues\event\ues_student_accepted::create(array(
                            'other' => array (
                                'ues_user_id' => $ues_user->id
                            )
                        ))->trigger();

                        break;

                    case 'teacher':
                        
                        if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')) {
                            cps_ues_handler::ues_teacher_process($ues_user);
                        }

                        break;
                    
                    default:
                        break;
                }
            }
        }

        // return any remaining UES users that were not enrolled during this process
        return $remaining_ues_users;
    }

    /**
     * Attempts to create a new UES user from a 'user' object pulled from the provider.
     * 
     * If there already exists a currently persisted user matching this pulled user's key details,
     * the user record will be update accordingly. For example, if the ID is the same but the username
     * is not, the email attribute will be updated based on the username and configured domain info.
     * 
     * @TODO - fix badgeslib issue
     * Note: The badgeslib.php event handler uses a run-time type hint which caused problems for ues enrollment handleProvisioning()
     *
     * @global type      $CFG
     * @param  object    $u
     * @return ues_user  $user
     * @throws Exception
     *
     * @throws EVENT-CORE: user_created
     * @throws EVENT-CORE: user_updated
     */
    private function createUser($u) {
        
        // create a UES user instance from the given user object
        $user = ues_user::upgrade($u);

        // construct query params for current user lookup check
        $by_idnumber = array('idnumber' => $u->idnumber);
        $by_username = array('username' => $u->username);
        $exact_params = $by_idnumber + $by_username;

        // if there exists a current user with the given 'idnumber' AND 'username'
        if ($persisted_user = ues_user::get($exact_params, true)) {
            
            // set the fresh instantiation's id to the current record's id
            $user->id = $persisted_user->id;
        
        // otherwise, if there exists a current user with this 'idnumber' ONLY
        } else if ( ! empty($u->idnumber) and $persisted_user = ues_user::get($by_idnumber, true)) {
            
            // set the fresh instantiation's id to the current record's id
            $user->id = $persisted_user->id;
            
            // assign an email attribute to the fresh instantiation based on 'username' and config options
            $user->email = $user->username . $this->config('user_email');

        // otherwise, if there exists a current user with this 'username' ONLY
        } else if ($persisted_user = ues_user::get($by_username, true)) {
            
            // set the fresh instantiation's id to the current record's id
            $user->id = $persisted_user->id;

        // otherwise, assign the user all of the default params
        } else {
            
            global $CFG;
            //@todo take a close look here - watch primary flag
            $user->email = $user->username . $this->config('user_email');
            $user->confirmed = $this->config('user_confirm');
            $user->city = $this->config('user_city');
            $user->country = $this->config('user_country');
            $user->lang = $this->config('user_lang');
            $user->firstaccess = time();
            $user->timecreated = $user->firstaccess;
            $user->auth = $this->config('user_auth');
            $user->mnethostid = $CFG->mnet_localhost_id; // always local user

            $created = true;
        }

        // if this is a "fresh" user, persist it
        if ( ! empty($created)) {
            
            $user->save();

            // @EVENT - user_created
            // @TODO - convert this to a core event?
            events_trigger_legacy('user_created', $user);

        // otherwise, if there was a current record but it has changed, attempt to save it
        } else if ($persisted_user and $this->userHasChanged($persisted_user, $user)) {
            
            try {
                
                $user->save();

            } catch (Exception $e) {
                
                // throw a formatted error
                $message = $e->getMessage();

                $error = "%s | Current %s | Stored %s";
                $log = "(%s: '%s')";

                $curr = sprintf($log, $user->username, $user->idnumber);
                $persisted_user = sprintf($log, $persisted_user->username, $persisted_user->idnumber);

                throw new Exception(sprintf($error, $message, $curr, $persisted_user));
            }

            // @EVENT - user_updated (unmonitored)
            // events_trigger_legacy('user_updated', (object)$user);
        }

        // if the enrollment provider sets an initial password upon user creation, handle it now
        if( isset($user->auth) and $user->auth === 'manual' and isset($user->init_password)) {
            
            // update the user's password to the provider's initial password config
            $user->password = $user->init_password;
            update_internal_user_password($user, $user->init_password);

            // let's not pass this any further.
            unset($user->init_password);

            // Need an instance of stdClass in the try stack.
            $userX = (array) $user;
            $userY = (object) $userX;

            // Force user to change password on next login.
            set_user_preference('auth_forcepasswordchange', 1, $userY);
        }

        return $user;
    }

    /**
     * Determine whether or not currently persisted UES user instance has been changed by comparing it to a candidate instantiation
     * 
     * This is decided by determining if the candidate user holds new information for a previously stored user.
     * (firstname, lastname, id, username, meta)
     * If it has changed, we will need to update the DB record.
     * 
     * @global object    $DB
     * @param  ues_user  $persisted_user  the currently persisted UES user instance
     * @param  ues_user  $candidate_user  the incoming UES user instance currently being evaluated at this point in the UES process.
     * @return boolean   $hasChanged
     *
     * @throws EVENT-UES: preferred_name_legitimized
     */
    private function userHasChanged(ues_user $persisted_user, ues_user $candidate_user) {
        
        $hasChanged = false;

        global $DB;
        $namefields   = user_picture::fields();
        $sql          = "SELECT id, idnumber, $namefields FROM {user} WHERE id = :id";

        // get the currently persisted user instance
        // @TODO ues_user does not currently upgrade with the alt names.
        $previousUser = $DB->get_record_sql($sql, array('id'=>$persisted_user->id));

        $hasPreferredName = !empty($previousUser->alternatename);

        // For users without preferred names, check that old and new firstnames match.
        $regUserFirstNameUnchanged = !$hasPreferredName && $previousUser->firstname == $candidate_user->firstname;

        // For users with preferred names, check that old altname matches incoming firstname.
        $prefUserFirstNameUnchanged = $hasPreferredName && $previousUser->alternatename == $candidate_user->firstname;

        // Composition of the previous two variables. If either is false, we need to take action and return 'true'.
        $firstNameUnchanged = $regUserFirstNameUnchanged || $prefUserFirstNameUnchanged;

        // We take action if last name has changed at all.
        $lastNameUnchanged = $previousUser->lastname == $candidate_user->lastname;

        // if either the first or last name has changed, mark the user as changed
        if( ! $firstNameUnchanged || ! $lastNameUnchanged) {
            
            // When the first name of a user who has set a preferred name changes, we reset the preference in CPS.
            if( ! $prefUserFirstNameUnchanged) {
                
                $DB->set_field('user', 'alternatename', NULL, array('id' => $previousUser->id));
                
                //events_trigger_legacy('preferred_name_legitimized', $candidate_user);
                /*
                 * Refactor events_trigger_legacy
                 */
                // @EVENT - preferred_name_legitimized
                global $CFG;
                
                if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                    require_once $CFG->dirroot.'/blocks/cps/events/ues.php';
                    cps_ues_handler::preferred_name_legitimized($candidate_user);
                }
            }

            $hasChanged = true;
        }

        // if the id number has changed, mark the user as changed
        if ($persisted_user->idnumber != $candidate_user->idnumber) {
            $hasChanged = true;
        }

        // if the username has changed, mark the user as changed
        if ($persisted_user->username != $candidate_user->username) {
            $hasChanged = true;
        }

        // get the candidate's meta data
        $candidate_meta = $candidate_user->meta_fields(get_object_vars($candidate_user));

        // if any meta data has changed, mark the user as changed
        foreach ($candidate_meta as $field) {
            
            if ( ! isset($persisted_user->{$field})) {
                $hasChanged = true;
            }

            if ($persisted_user->{$field} != $candidate_user->{$field}) {
                $hasChanged = true;
            }
        }
        
        return $hasChanged;
    }

    /**
     * Process enrollment for provisioned sections.
     * 
     * Pending sections become unenrolled. Processed sections become enrolled.
     * 
     * @return null
     */
    public function handleEnrollment() {

        $this->log('Beginning manifestation...');

        // unenroll pending sections
        $this->handlePendingSectionEnrollment();

        // enroll processed sections
        $this->handleProcessedSectionEnrollment();
    }

    /**
     * Unenroll course's sections
     * 
     * Given an input array of ues_sections, remove them and their enrollments from active status.
     * 
     * If the section HAS NOT been manifested, set its status to SKIPPED.
     * If it HAS been manifested, get a reference to the Moodle course. Get the students and teachers enrolled in the course and unenroll them.
     * Finally, set the idnumber to the empty string ''.
     * 
     * @return null
     *
     * @throws EVENT-UES: ues_course_severed
     */
    public function handlePendingSectionEnrollment() {
        
        global $DB, $USER;

        // fetch all pending UES sections
        $ues_sections = ues_section::get_all(array('status' => ues::PENDING));

        // if there are pending sections, handle enrollment
        if ($ues_sections) {

            $this->log('Found ' . count($ues_sections) . ' pending sections that will be unenrolled.');

            foreach ($ues_sections as $ues_section) {
                
                // this section has not been manifested, mark as SKIPPED and move on
                if ( ! $ues_section->is_manifested()) {

                    $ues_section->status = ues::SKIPPED;
                    $ues_section->save();

                } else {

                    // get moodle course for this section
                    $course = $ues_section->moodle();

                    // get UES course for this section
                    $ues_course = $ues_section->course();

                    // unenroll all students and teachers
                    foreach (array('student', 'teacher') as $user_type) {

                        // get the moodle group for this course/section criteria
                        $group = $this->manifestGroup($course, $ues_course, $ues_section);

                        // get all UES type users
                        $class = 'ues_' . $user_type;

                        $user_params = ues::where()
                            ->sectionid->equal($ues_section->id)
                            ->status->in(ues::ENROLLED, ues::UNENROLLED);

                        $ues_users = $class::get_all($user_params);
                        
                        // uneroll these users
                        $this->unenrollUsers($group, $ues_users);
                    }

                    // set course visibility according to user preferences (block_cps)
                    
                    // @TODO - make sure cps is installed
                    
                    // get this user's 'creation_visible' preference, default to false
                    $setting_params = ues::where()
                        ->userid->equal($USER->id)
                        ->name->starts_with('creation_');

                    $settings = cps_setting::get_to_name($setting_params);
                    $setting = ( ! empty($settings['creation_visible'])) ? $settings['creation_visible'] : false;

                    // @TODO - what is going on here?
                    $course->visible = isset($setting->value) ? $setting->value : get_config('moodlecourse', 'visible');

                    $DB->update_record('course', $course);

                    $this->log('Unloading ' . $course->idnumber);

                    //events_trigger_legacy('ues_course_severed', $course);
                    // @EVENT - ues_course_severed
                    /**
                     * Refactor events_trigger_legacy().
                     */
                    global $CFG;
                    
                    if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                        require_once $CFG->dirroot.'/blocks/cps/events/ues.php';
                        cps_ues_handler::ues_course_severed($course);
                    }

                    $ues_section->idnumber = '';
                }
                
            }

            $this->log('');
        }
    }

    /**
     * Fetches existing or creates new group based on given params
     * 
     * @global type $DB
     * 
     * @param stdClass     $moodle_course  object from {course}
     * @param ues_course   $ues_course     object from {enrol_ues_courses}
     * @param ues_section  $user_section   object from {enrol_ues_sections}
     * @return stdClass object from {groups}
     */
    private function manifestGroup($moodle_course, $ues_course, $user_section) {
        
        global $DB;

        // set query params for this group
        $group_params = array(
            'courseid' => $moodle_course->id,
            'name' => "{$ues_course->department} {$ues_course->cou_number} {$user_section->sec_number}"
        );

        // if there is no group matching these params, create one
        if ( ! $group = $DB->get_record('groups', $group_params)) {
            $group = (object) $group_params;
            
            $group->id = groups_create_group($group);
        }

        return $group;
    }

    /**
     * Gets a moodle enrolment instance
     * 
     * @param  int  $courseid
     * @return {enrol}
     */
    public function get_instance($courseid) {
        
        global $DB;

        // get moodle enrolment instances for this course
        $instances = enrol_get_instances($courseid, true);

        // get only UES enrolment instances
        $attempt = array_filter($instances, function($in) {
            return $in->enrol == 'ues';
        });

        // if there are no instances, create one
        if (empty($attempt)) {
            
            $course_params = array('id' => $courseid);
            $course = $DB->get_record('course', $course_params);

            $id = $this->add_instance($course);

            return $DB->get_record('enrol', array('id' => $id));

        } else {
            return current($attempt);
        }
    }

    /**
     * Unenrolls UES users from both UES and moodle enrollment
     * 
     * This method takes into consideration whether or not the UES user holds another role within this course and preserves that
     * 
     * @param  moodle group  $group
     * @param  ues_student[] | ues_teacher[]  $ues_users [description]
     * @return null
     *
     * @throws EVENT-UES: ues_group_emptied
     * @throws EVENT-UES: ues_student_unenroll
     * @throws EVENT-UES: ues_teacher_unenroll
     * @throws EVENT-CORE: user_enrolment_deleted
     * @throws EVENT-CORE: group_member_added
     * @throws EVENT-CORE: group_deleted
     */
    private function unenrollUsers($group, $ues_users) {
        
        global $DB;

        $instance = $this->get_instance($group->courseid);

        $course = $DB->get_record('course', array('id' => $group->courseid));

        foreach ($ues_users as $ues_user) {
            
            // get UES user type (student, teacher)
            $ues_type = $this->determineUserRole($ues_user);

            // get moodle role key
            $roleid = $this->config($ues_type . '_role');

            // Ignore pending statuses for users who have no role assignment
            $context = context_course::instance($course->id);
            if ( ! is_enrolled($context, $ues_user->userid)) {
                continue;
            }

            // remove this user from the group
            groups_remove_member($group->id, $ues_user->userid);

            // Don't mark those meat to be unenrolled to processed
            $prev_status = $ues_user->status;

            // if this UES user was PENDING or UNENROLLED, their status will be set to UNENROLLED,
            // otherwise, it will be set to PROCESSED
            $to_status = ($ues_user->status == ues::PENDING or $ues_user->status == ues::UNENROLLED) ?
                ues::UNENROLLED :
                ues::PROCESSED;

            // update status
            $ues_user->status = $to_status;
            $ues_user->save();

            // get all sections that this user is enrolled in
            $ues_sections = $ues_user->sections_by_status(ues::ENROLLED);

            $is_enrolled = false;
            $same_section = false;

            foreach ($ues_sections as $section) {
                
                if ($section->idnumber == $course->idnumber) {
                    $is_enrolled = true;
                }

                // This user is enrolled as another role in the same section
                if ($section->id == $ues_user->sectionid) {
                    $same_section = true;
                }
            }

            // this user is enrolled as another role (teacher) in the same section so keep groups alive
            if ( ! $is_enrolled) {
                
                // unenroll user from course using moodle's native enrol_plugin method
                // @EVENT \core\event\user_enrolment_deleted
                $this->unenrol_user($instance, $ues_user->userid, $roleid);

            } else if ($same_section) {

                // keep this user in the group
                // @EVENT \core\event\group_member_added
                groups_add_member($group->id, $ues_user->userid);
            }

            // if we're changing the status to UNENROLLED, fire an event
            if ($to_status != $prev_status and $to_status == ues::UNENROLLED) {
                $event_params = array(
                    'group' => $group,
                    'ues_user' => $ues_user
                );

                // @EVENT - ues_student_unenroll | ues_teacher_unenroll (unmonitored)
                //events_trigger_legacy('ues_' . $ues_type . '_unenroll', $event_params);
            }
        }

        // if there are no more members of this group, delete it
        $count_params = array('groupid' => $group->id);
        
        if ( ! $DB->count_records('groups_members', $count_params)) {
            
            // Going ahead and delete as delete
            // @EVENT - \core\event\group_deleted
            groups_delete_group($group);

            // @EVENT - ues_group_emptied (no-op)
            // @see cps_ues_handler::ues_group_emptied()
            // events_trigger_legacy('ues_group_emptied', $group);
        }
    }

    /**
     * Determine a ues_user's role based on the presence and setting of a field primary_flag
     * 
     * @param  type    $ues_user
     * @return string  editingteacher | teacher | student
     */
    private function determineUserRole($ues_user) {
        
        if (isset($ues_user->primary_flag)) {
            $role = $ues_user->primary_flag ? 'editingteacher' : 'teacher';
        } else {
            $role = 'student';
        }
        return $role;
    }

    /**
     * Handle courses to be manifested.
     *
     * For each incoming section, manifest the course and update its status to
     * ues::Manifested.
     *
     * Skip any incoming section whose status is ues::PENDING.
     *
     */
    public function handleProcessedSectionEnrollment() {
        
        // fetch all processed sections
        $ues_sections = ues_section::get_all(array('status' => ues::PROCESSED));

        // if there are any processed sections, enroll them now
        if ($ues_sections) {
            $this->log(' => Found ' . count($ues_sections) . ' ' . $this->pluralize(count($ues_sections), 'section') . ' ready to be manifested.');
            $this->log('');

            foreach ($ues_sections as $ues_section) {

                // if the sections status is PENDING, make sure it is skipped
                if ($ues_section->status == ues::PENDING) {
                    continue;
                }

                $ues_semester = $ues_section->semester();
                $ues_course = $ues_section->course();

                $success = $this->manifestCourse($ues_semester, $ues_course, $ues_section);

                if ($success) {
                    $ues_section->status = ues::MANIFESTED;
                    $ues_section->save();
                }
            }

            $this->log('');
        }
    }

    /**
     * Create all moodle objects for a given course.
     *
     * This method oeprates on a single section at a time.
     *
     * It's first action is to determine if a primary instructor change
     * has happened. This case is indicated by the existence, in {ues_teachers}
     * of two records for this section with primary_flag = 1. If one of those
     * records has status ues::PROCESSED (meaning: the new primary inst)
     * and the other has status ues::PENDING (meaning the old instructor,
     * marked for disenrollment), then we know a primary instructor swap is taking
     * place for the section, therefore, we trigger the
     * @link https://github.com/lsuits/ues/wiki/Events ues_primary_change event.
     *
     * Once the event fires, subscribers, such as CPS, have the opportunity to take
     * action on the players in the instructor swap.
     *
     * With respect to the notion of manifestation, the real work of this method
     * begins after handing instructor swaps, namely, manifesting the course and
     * its enrollments.
     *
     * @see ues_enrol_plugin::handleCourseManifestation
     * @see ues_enrol_plugin::manifestCourseEnrollment
     * @param ues_semester $ues_semester
     * @param ues_course   $ues_course
     * @param ues_section  $ues_section
     * @return boolean
     *
     * @throws EVENT-UES: ues_primary_change
     */
    private function manifestCourse($ues_semester, $ues_course, $ues_section) {
        
        // construct params to find primary teachers within this section
        $teacher_params = array(
            'sectionid' => $ues_section->id,
            'primary_flag' => 1
        );

        // get this section's new primary UES teacher
        $new_primary = ues_teacher::get($teacher_params + array(
            'status' => ues::PROCESSED
        ));

        // get this section's old primary UES teacher
        $old_primary = ues_teacher::get($teacher_params + array(
            'status' => ues::PENDING
        ));

        // if there's no old primary, check to see if there's an old non-primary
        if( ! $old_primary){
            
            $old_primary = ues_teacher::get(array(
                'sectionid'    => $ues_section->id,
                'status'       => ues::PENDING,
                'primary_flag' => 0
            ));

            // if there was an old non-primary, and it is set to be the new primary, there is no 'primary change'
            if ($old_primary) {
                $old_primary = ($old_primary->userid == $new_primary->userid) ? false : $old_primary;
            }
        }

        // Campuses may want to handle primary instructor changes differently
        if ($new_primary and $old_primary) {

            global $DB;
            
            // get each moodle user
            $new = $DB->get_record('user', array('id' => $new_primary->userid));
            $old = $DB->get_record('user', array('id' => $old_primary->userid));
            
            // log this change
            $this->log(sprintf("Instructor change from %s to %s\n", $old->username, $new->username));

            // fire an event to notify CPS
            // @EVENT - ues_primary_change
            $data = new stdClass;
            $data->section = $ues_section;
            $data->old_primary = $old_primary;
            $data->new_primary = $new_primary;
            
            // events_trigger_legacy('ues_primary_change', $data);
            /**
             * Refactor events_trigger()
             */
            global $CFG;
            if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                require_once $CFG->dirroot.'/blocks/cps/events/ues.php';
                $data = cps_ues_handler::ues_primary_change($data);
            }

            $ues_section = $data->section;
        }

        // manifest this moodle course by creating or updateing it
        $moodle_course = $this->handleCourseManifestation($ues_semester, $ues_course, $ues_section);

        $this->manifestCourseEnrollment($moodle_course, $ues_course, $ues_section);

        return true;
    }

    /**
     * Handles the manifestation of a moodle course for the specified UES semester, course, and section
     * 
     * Note: this method takes into consideration the fact that there may already be an existing moodle course
     * 
     * @param  ues_semester  $ues_semester
     * @param  ues_course    $ues_course
     * @param  ues_section   $ues_section
     * @return {course}
     *
     * @throws EVENT-UES: ues_course_create
     * @throws EVENT-CORE: course_created
     */
    private function handleCourseManifestation($ues_semester, $ues_course, $ues_section) {
        
        global $DB;
        
        // get this UES section's primary teacher
        $primary_teacher = $ues_section->primary();

        // if there is no primary teacher set, grab one
        // @TODO - arbitrary? do we need a better way of selecting, email notice?
        if ( ! $primary_teacher) {
            $primary_teacher = current($ues_section->teachers());
        }

        // Take into consideration of outside forces manipulating idnumbers
        // Therefore we must check the section's idnumber before creating one
        // Possibility the course was deleted externally

        // construct course's expected id number
        $expectedIdNumber = $ues_semester->year . $ues_semester->name .
            $ues_course->department . $ues_semester->session_key . $ues_course->cou_number .
            $primary_teacher->userid;

        // set the course id number
        $idnumber = ( ! empty($ues_section->idnumber)) ? $ues_section->idnumber : $expectedIdNumber;

        // get moodle course with this id number, if it exists
        $moodle_course = $DB->get_record('course', array('idnumber' => $idnumber));

        // if there is no moodle course, create it
        if ( ! $moodle_course) {
            
            // get the primary teacher moodle user
            $user = $primary_teacher->user();

            // get the formatted semester session key, returning an empty string if one is not set
            $session = empty($ues_semester->session_key) ? '' : '(' . $ues_semester->session_key . ') ';

            // get the manifested moodle category
            $category = $this->manifestCategory($ues_course);

            // use plugin configuration to construct a shortname and fullname for the course
            $a = new stdClass;
            $a->year = $ues_semester->year;
            $a->name = $ues_semester->name;
            $a->session = $session;
            $a->department = $ues_course->department;
            $a->course_number = $ues_course->cou_number;
            $a->fullname = fullname($user);
            $a->userid = $user->id;

            $sn_pattern = $this->config('course_shortname');
            $fn_pattern = $this->config('course_fullname');

            $shortname = ues::format_string($sn_pattern, $a);
            $assumed_fullname = ues::format_string($fn_pattern, $a);

            // set all course params
            $moodle_course = new stdClass;
            $moodle_course->idnumber = $idnumber;
            $moodle_course->shortname = $shortname;
            $moodle_course->fullname = $assumed_fullname;
            $moodle_course->category = $category->id;
            $moodle_course->summary = $ues_course->fullname;
            $moodle_course->startdate = $ues_semester->classes_start;

            // Handle system creation defaults
            $defaultSettings = array('visible','format','lang','groupmode','groupmodeforce', 'hiddensections', 'newsitems','showgrades','showreports','maxbytes','enablecompletion', 'completionstartonenrol','numsections', 'legacyfiles');
        
            // Set system defaults
            foreach ($defaultSettings as $key) {
                $moodle_course->$key = get_config('moodlecourse', $key);
            }

            // Actually needs to happen, before the create call
            //events_trigger_legacy('ues_course_create', $moodle_course);
            // @EVENT - ues_course_create
            
            /*
             * Refactor events_trigger_legacy call
             */
            global $CFG;
            if (file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')) {
                require_once $CFG->dirroot.'/blocks/cps/events/ues.php';
                $moodle_course = cps_ues_handler::ues_course_create($moodle_course);
            }

            try {
                
                // @EVENT fired: \core\event\course_created
                $moodle_course = create_course($moodle_course);

                // create a moodle instance
                $this->add_instance($moodle_course);

            } catch (Exception $e) {

                // log formatted error message
                $this->logError(ues::_s('error_shortname', $moodle_course));

                // get the ID number the moodle course trying to be created
                $idnumber = $moodle_course->idnumber;

                // get the already existing moodle course
                $moodle_course = $DB->get_record('course', array('shortname' => $moodle_course->shortname));
                
                // set the existing course's id number to the new id number
                $moodle_course->idnumber = $idnumber;

                // attempt to update the existing moodle course
                if ( ! $DB->update_record('course', $moodle_course)) {
                    // if it can't be done, log the error
                    $this->logError('Could not update course: ' . $moodle_course->idnumber);
                }
            }
        }

        // if there is no idnumber set for this UES section, update it to this moodle course's idnumber
        if ( ! $ues_section->idnumber) {
            $ues_section->idnumber = $moodle_course->idnumber;
            $ues_section->save();
        }

        return $moodle_course;
    }

    /**
     * Manifest a given UES course's category within Moodle, if one does not exist, and return it
     * 
     * @param  ues_course  $ues_course
     * @return {course_categories}
     */
    public function manifestCategory($ues_course) {
        
        global $DB;

        // get any existing moodle category for this UES course
        $category = $DB->get_record('course_categories', array('name' => $ues_course->department));

        // if there is no category, create one
        if ( ! $category) {
            
            $category = new stdClass;
            $category->name = $ues_course->department;
            $category->sortorder = 999;
            $category->parent = 0;
            $category->description = 'Courses under ' . $ues_course->department;
            $category->id = $DB->insert_record('course_categories', $category);
        }

        return $category;
    }

    /**
     * Manifest enrollment for a given course section by enrolling or unenrolling users (students or teachers)
     * as necessary depending on their UES status
     *
     * @param object       $moodle_course  object from {course}
     * @param ues_course   $ues_course
     * @param ues_section  $ues_section
     */
    private function manifestCourseEnrollment($moodle_course, $ues_course, $ues_section) {
        
        // get moodle group
        $group = $this->manifestGroup($moodle_course, $ues_course, $ues_section);

        $general_params = array('sectionid' => $ues_section->id);

        $actions = array(
            ues::PROCESSED => 'enroll',
            ues::PENDING => 'unenroll'
        );

        $enroll_count = 0;
        $unenroll_count = 0;

        foreach (array('teacher', 'student') as $type) {
            $class = 'ues_' . $type;

            foreach ($actions as $status => $action) {

                // construct query params for finding this UES user type with this status
                $action_params = $general_params + array('status' => $status);

                // set enroll_count or unenroll_count
                ${$action . '_count'} = $class::count($action_params);

                // handle user enrolls/unenrolls as necessary
                if (${$action . '_count'}) {
                    try {
                        $to_action = $class::get_all($action_params);
                        $this->{$action . 'Users'}($group, $to_action);
                    } catch (Exception $e) {
                        // This will only happen if there are no more
                        // teachers and students are set to be enrolled
                        // We should log it as a potential error and continue.
                        $this->logError(ues::_s('error_no_group', $group));
                    }
                }
            }
        }

        // if any enrollment changes were made, log them
        if ($unenroll_count or $enroll_count) {
            
            $this->log('Manifesting: ' . $moodle_course->idnumber . ' ' . $ues_section->sec_number . '...');

            if ($unenroll_count) {
                $this->log(' => Unenrolled ' . $unenroll_count . ' ' . $this->pluralize($unenroll_count, 'user') . '.');
            }

            if ($enroll_count) {
                $this->log(' => Enrolled ' . $enroll_count . ' ' . $this->pluralize($enroll_count, 'user') . '.');
            }
        }
    }

    /**
     * Enrolls UES users in both UES and moodle enrollment
     * 
     * This method takes into consideration whether or not the user's grades should be recovered
     * 
     * @param  moodle group  $group
     * @param  ues_student[] | ues_teacher[]  $ues_users [description]
     * @return null
     *
     * @throws EVENT-CORE: user_enrolment_created
     * @throws EVENT-CORE: group_member_added
     * @throws EVENT-UES: ues_teacher_enroll
     * @throws EVENT-UES: ues_student_enroll
     */
    private function enrollUsers($group, $ues_users) {
        
        $instance = $this->get_instance($group->courseid);

        // pull recover grades setting
        $recover = $this->config('recover_grades');

        // require grade libs if they will be necessary
        if ( $recover and ! function_exists('grade_recover_history_grades')) {
            global $CFG;
            require_once $CFG->libdir . '/gradelib.php';
        }

        // create callback function to recover grades for each user, if enabled
        $recover_grades_for = function($ues_user) use ($recover, $instance) {
            if ($recover) {
                grade_recover_history_grades($ues_user->userid, $instance->courseid);
            }
        };

        // iterate through each user
        foreach ($ues_users as $ues_user) {
            
            // calculate role id
            $shortname = $this->determineUserRole($ues_user);
            
            $roleid = $this->config($shortname . '_role');

            // enroll user from course using moodle's native enrol_plugin method
            // @EVENT \core\event\user_enrolment_created
            $this->enrol_user($instance, $ues_user->userid, $roleid);

            // add user to moodle group
            // @EVENT \core\event\group_member_added
            groups_add_member($group->id, $ues_user->userid);

            // if enabled, attempt to recover any old grades for this user
            $recover_grades_for($ues_user);

            // update this UES user's status to ENROLLED
            $ues_user->status = ues::ENROLLED;
            $ues_user->save();

            $event_params = array(
                'group' => $group,
                'ues_user' => $ues_user
            );

            // Unmonitored event.
            // @EVENT - ues_student_enroll (unmonitored)
            // @EVENT - ues_teacher_enroll (unmonitored)
            //events_trigger_legacy('ues_' . $shortname . '_enroll', $event_params);
        }
    }

    /**
     * Emails UES logs to admins.
     *
     * By default do NOT email message logs, but error logs only
     * 
     * @param  boolean  $send  for testing purposes
     * @return null
     */
    public function emailReports($send = true) {
        
        if ( ! $send)
            return;

        global $CFG;

        // get all moodle admin users
        $admins = get_admins();

        // if there are any logged errors, email them
        if ( ! empty($this->errorLog)) {
            
            // format the error log
            $errorLogText = implode("\n", $this->errorLog);

            // email error log to each admin
            foreach ($admins as $admin) {
                email_to_user($admin, ues::_s('pluginname'), sprintf('[SEVERE] UES Error Log [%s]', $CFG->wwwroot), $errorLogText);
            }
        }

        // mail the message log?
        if ($this->config('email_report')) {
            
            // format the message log
            $messageLogText = implode("\n", $this->messageLog);

            // email message log to each admin
            foreach ($admins as $admin) {
                email_to_user($admin, ues::_s('pluginname'), sprintf('UES Message Log [%s]', $CFG->wwwroot), $messageLogText);
            }
        }
    }

    /**
     * Attempt to handle any errors.
     *
     * This method will NOT run if UES is still running, or if there are too many errors as determined by configuration
     * 
     * @return null
     */
    public function handleAutomaticErrors($emailReport = true) {
        
        // fetch all UES errors
        $ues_errors = $this->fetchErrors();

        if ($ues_errors) {

            // don't attempt to reprocess errors if UES is still running
            if ($this->isRunning()) {
                $message = 'Attempting to handle UES errors but process is still running. Please try reprocessing errors again later.';
                $this->logError($message);
                throw new UESException($message);
                return;
            }

            // don't attempt to reprocess errors if there are too many errors
            $errorThreshold = $this->config('error_threshold');

            if (count($ues_errors) > $errorThreshold) {
                $message = ues::_s('error_threshold_log');
                $this->logError($message);
                throw new UESException($message);
                return;
            }

            // attempt to reprocess errors (re-running enrollment if necessary) and then send an email report to the admins
            ues::reprocessErrors($ues_errors, $emailReport);
        }
    }

    /**
     * Resets unenrollments for the given UES section by creating a moodle group and unenrolling that entire group
     * 
     * @param  ues_section  $ues_section
     * @return null
     */
    public function resetSectionUnenrollments($ues_section) {
        
        // get moodle course from this UES section
        $moodle_course = $ues_section->moodle();

        // if no course exists, ignore this command
        if (empty($moodle_course)) {
            return;
        }

        // get the UES course from this UES section
        $ues_course = $ues_section->course();

        // iterate through each UES user type, unenrolling users within this course/section
        foreach (array('student', 'teacher') as $type) {
            
            $group = $this->manifestGroup($moodle_course, $ues_course, $ues_section);

            $class = 'ues_' . $type;

            $params = array(
                'sectionid' => $ues_section->id,
                'status' => ues::UNENROLLED
            );

            $ues_users = $class::get_all($params);

            $this->unenrollUsers($group, $ues_users);
        }
    }

    /**
     * Outputs a message to the console and adds it to message log
     * 
     * @param  string  $message
     * @return null
     */
    public function log($message = '') {
        
        if ( ! $this->is_silent) {
            $this->output($message);
        }
        
        $this->addToMessageLog($message);
    }

    /**
     * Outputs an error message to the console and adds it to the message and error logs
     * 
     * @param  string  $message
     * @return null
     */
    public function logError($message = '') {
        
        if ( ! $this->is_silent) {
            $this->output($message);
        }

        if ($message) {
            $this->addToErrorLog($message);
        }
    }

    /**
     * Outputs a log message to the console
     * 
     * @param  string  $message
     * @return null
     */
    private function output($message = '') {
        mtrace($message);
    }

    /**
     * Adds a message to the message log array
     * 
     * @param  string  $message
     * @return null
     */
    private function addToMessageLog($message = '') {
        $this->messageLog[] = $message;
    }

    /**
     * Adds an error message to the error log array and, by default, adds it to the message log as well
     * 
     * @param  string   $message
     * @param  boolean  $addToMessageLog
     * @return null
     */
    private function addToErrorLog($message = '', $addToMessageLog = true) {
        $this->errorLog[] = $message;
        
        if ($message and $addToMessageLog) {
            $this->messageLog[] = 'ERROR: ' . $message;
        }
    }

    /**
     * Sets or gets a UES config value
     * 
     * @param  string  $key
     * @param  mixed   $value
     * @return mixed 
     */
    public function config($key, $value = false, $setToFalse = false) {
        if ($value || ( ! $value and $setToFalse)) {
            return set_config($key, $value, 'enrol_ues');
        } else {
            return get_config('enrol_ues', $key);
        }
    }

    /**
     * Fetches all saved UES errors
     * 
     * @return ues_error[]
     */
    private function fetchErrors() {

        // fetch all UES errors
        $ues_errors = ues_error::get_all();

        return $ues_errors;
    }

    /**
     * Returns a count of all saved UES errors
     * 
     * @return int
     */
    public function getErrorCount() {

        $ues_errors = $this->fetchErrors();

        return count($ues_errors);
    }

    /**
     * Returns a properly pluralized word based on the count
     *
     * An optional pluralized word may be sent through, otherwise, defaults to an 's' appeneded to singular
     * 
     * @param  int     $count
     * @param  string  $singular
     * @param  string  $plural
     * @return string
     */
    private function pluralize($count, $singular, $plural = false) {
        
        if ( ! $plural)
            $plural = $singular . 's';
        
        return ($count == 1) ? $singular : $plural;
    }


    ////////  EVENTY TYPE THINGS.... are these used?
    

    public function course_updated($inserted, $course, $data) {
        // UES is the one to create the course
        if ($inserted) {
            return;
        }

        // Delete extension handler
        // 2015-02-23 Not finding any handlers for this, there is no reason to
        // refactor it to use either Events 2.
        //events_trigger_legacy('ues_course_updated', array($course, $data));
    }

    public function course_edit_validation($instance, array $data, $context) {
        $errors = array();
        if (is_null($instance)) {
            return $errors;
        }

        $system = context_system::instance();
        $can_change = has_capability('moodle/course:update', $system);

        $restricted = explode(',', $this->config('course_restricted_fields'));

        foreach ($restricted as $field) {
            if ($can_change) {
                continue;
            }

            $default = get_config('moodlecourse', $field);
            if (isset($data[$field]) and $data[$field] != $default) {
                $errors[$field] = ues::_s('bad_field');
            }
        }

        // Delegate extension validation to extensions
        $event = new stdClass;
        $event->instance = $instance;
        $event->data = $data;
        $event->context = $context;
        $event->errors = $errors;

        //Unmonitored event.
        //events_trigger_legacy('ues_course_edit_validation', $event);

        return $event->errors;
    }

    /**
     *
     * @param type $instance
     * @param MoodleQuickForm $form
     * @param type $data
     * @param type $context
     * @return type
     */
    public function course_edit_form($instance, MoodleQuickForm $form, $data, $context) {
        if (is_null($instance)) {
            return;
        }

        // Allow extension interjection
        $event = new stdClass;
        $event->instance = $instance;
        $event->form = $form;
        $event->data = $data;
        $event->context = $context;

        //Unmonitored event.
        //events_trigger_legacy('ues_course_edit_form', $event);
    }

    public function add_course_navigation($nodes, stdClass $instance) {
        global $COURSE;
        // Only interfere with UES courses
        if (is_null($instance)) {
            return;
        }

        $coursecontext = context_course::instance($COURSE->id);
        $can_change = has_capability('moodle/course:update', $coursecontext);
        if ($can_change) {
            if ($this->config('course_form_replace')) {
                $url = new moodle_url(
                    '/enrol/ues/edit.php',
                    array('id' => $instance->courseid)
                );
                $nodes->parent->parent->get('editsettings')->action = $url;
            }
        }

        // Allow outside interjection
        $params = array($nodes, $instance);

        /**
         * Refactor events_trigger_legacy()
         */
        global $CFG;
        if(file_exists($CFG->dirroot.'/blocks/ues_reprocess/eventslib.php')){
            require_once $CFG->dirroot.'/blocks/ues_reprocess/eventslib.php';
            ues_event_handler::ues_course_settings_navigation($params);
        }
        //events_trigger_legacy('ues_course_settings_navigation', $params);
    }

}

function enrol_ues_supports($feature) {
    switch ($feature) {
        case ENROL_RESTORE_TYPE:
            return ENROL_RESTORE_EXACT;

        default:
            return null;
    }
}
