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

        ues::require_daos();
        ues::require_exceptions();
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

            $this->_provider = $this->loadSelectedProvider();
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
     * @return boolean $success ?
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
        ues::require_libs();
        
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
     * Sets UES running status
     * 
     * @param  boolean  $isRunning  whether or not UES is running
     * @return null
     */
    public function nowRunning($isRunning) {
        $this->config('running', $isRunning, ! $isRunning);
        $this->log(($isRunning) ? 'Process is now running.' : 'Process has stopped.', true);
    }

    /**
     * Fetches filtered UES semesters and process enrollment for each.
     * 
     * @return null
     */
    public function runEnrollmentProcess() {

        // get all semesters that are considered valid right now
        $semesters = $this->getUesSemesters(time());

        foreach ($semesters as $semester) {
            $this->processUesSemester($semester);
        }
    }

    /**
     * Fetches semesters from the enrollment provider, convert to ues_semesters, persist, and return filtered collection
     * 
     * @param  int  $time  timestamp
     * @return ues_semester[]
     *
     */
    private function getUesSemesters($time) {

        // calculate date threshold for semester query
        $sub_days = $this->calculateSubDaysTime();
        $formattedDate = ues::format_time($time - $sub_days);

        $this->log('Pulling semesters for ' . $formattedDate . '...');

        try {
            
            // gets semester data source
            $semester_source = $this->provider()->semester_source();
            
            // fetch semesters from data source
            // @TODO - it doesn't look like this function is using the data parameter. problem? looks like they are filtered correctly later on though...
            $semesters = $semester_source->semesters($formattedDate);

            $this->log('Found ' . count($semesters) . ' semesters...', true);
            
            // convert fetched semesters into persisted ues_semester collection
            $ues_semesters = $this->convertSemesters($semesters);

            // filter out invalid, ignored, or out-of-date-range semesters
            $filteredSemesters = $this->filterUesSemesters($ues_semesters, $time);

            return $filteredSemesters;

        } catch (Exception $e) {

            $this->logError($e->getMessage());
            return array();
        }
    }

    /**
     * Returns the UES sub_days config, formatted
     * 
     * @return int
     */
    private function calculateSubDaysTime() {
        $set_days = (int) $this->config('sub_days');
        $sub_days = 24 * $set_days * 60 * 60;

        return $sub_days;
    }

    /**
     * Returns a filtered ues_semester collection based on: validity, ignore config, and date
     * 
     * @param  ues_semesters[]  $ues_semesters
     * @param  int  $time  timestamp
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
     * Receives semesters pull from the data source and persists them as ues_semesters
     * 
     * @param  stdClass[year, name, campus, session_key, classes_start] $semesters
     * @return ues_semester[]
     */
    private function convertSemesters($semesters) {
        
        $ues_semesters = array();

        foreach ($semesters as $semester) {
            try {
                $params = array(
                    'year'        => $semester->year,
                    'name'        => $semester->name,
                    'campus'      => $semester->campus,
                    'session_key' => $semester->session_key
                );

                // convert this semester to a ues_semester
                $ues_semester = ues_semester::upgrade_and_get($semester, $params);

                // if classes have not yet begun, do not process any further
                if (empty($ues_semester->classes_start)) {
                    continue;
                }

                // Call event before potential insert, as to notify creation
                // events_trigger_legacy('ues_semester_process', $ues_semester);
                // @EVENT (unmonitored)

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
     * Process enrollment for a specific UES semester
     * 
     * @param  ues_semester[]  $ues_semester
     * @return null
     */
    private function processUesSemester($ues_semester) {

        $this->log('Pulling courses/sections for ' . $ues_semester . '...');

        // fetch courses within this semester
        $ues_courses = $this->getUesCourses($ues_semester);

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

            $this->process_semester_by_section($ues_semester, $ues_courses);

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
    public function getUesCourses($ues_semester) {
        
        try {

            // gets course data source
            $course_source = $this->provider()->course_source();

            // fetch this semester's courses from data source
            $courses = $course_source->courses($ues_semester);

            $this->log('Found ' . count($courses) . ' courses...', true);

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
     * Receives a semester's course pull from the data source and persists them and their sections as ues_courses and ues_sections, respectively
     * 
     * @param  stdClass[year, name, campus, session_key, classes_start] $courses
     * @param  ues_semester  $ues_semester
     * @return ues_course[]
     */
    public function convertCourses($courses, $ues_semester) {
        
        $ues_courses = array();

        foreach ($courses as $course) {
            
            try {
                
                $params = array(
                    'department' => $course->department,
                    'cou_number' => $course->cou_number
                );

                $ues_course = ues_course::upgrade_and_get($course, $params);

                // Call event before potential insert, as to notify creation
                // events_trigger_legacy('ues_course_process', $ues_course);
                // @EVENT (unmonitored)

                // persist to {ues_courses}
                $ues_course->save();

                // convert this course's sections to persisted ues_section collection
                $ues_sections = $this->convertCourseSections($ues_course, $ues_semester);

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
     * @param  ues_course    $ues_course
     * @param  ues_semester  $ues_semester
     * @return ues_section[]
     */
    public function convertCourseSections($ues_course, $ues_semester) {

        $ues_sections = array();
                
        foreach ($ues_course->sections as $section) {
            
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
            $user_types = ['teacher', 'student'];

            foreach ($user_types as $user_type) {
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

            $user_types = ['teacher', 'student'];

            foreach ($user_types as $user_type) {
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
            // @EVENT
            /**
             * Refactor old events_trigger_legacy
             */
            global $CFG;
            if(file_exists($CFG->dirroot.'/bloicks/cps/events/ues.php')){
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
     * @param  string                         $user_type      teacher|student
     * @param  ues_section                    $semester
     * @param  string                         $department
     * @param  object[]                       $pulled_users   incoming users from the provider
     * @param  ues_teacher[] | ues_student[]  $current_users  all UES users for this semester
     */
    private function fillUserRolesByDepartment($user_type, $semester, $department, $pulled_users, $current_users) {
        
        foreach ($pulled_users as $pulled_user) {
            
            // find the UES course from the given department and the pulled user's course number
            $course_params = array(
                'department' => $department,
                'cou_number' => $pulled_user->cou_number
            );

            $course = ues_course::get($course_params);

            // if a course does not exist, proceed to the next user
            if (empty($course)) {
                continue;
            }

            // find the UES section from this UES course for the given department and the pulled user's section number
            $section_params = array(
                'semesterid' => $semester->id,
                'courseid'   => $course->id,
                'sec_number' => $pulled_user->sec_number
            );

            $section = ues_section::get($section_params);

            // if a section does not exist, proceed to the next user
            if (empty($section)) {
                continue;
            }
            
            // @HERE is where i left off!!!

            // process this user
            $this->processUserByType($user_type, $section, array($pulled_user), $current_users);
            // $this->{'process_'.$user_type.'s'}($section, array($user), $current_users);
        }

        $this->release($user_type, $current_users);
    }

    /**
     * Process a given user type
     *
     * @param  string                         $user_type
     * @param  ues_section                    $ues_section
     * @param  object[]                       $users
     * @param  (ues_student | ues_teacher)[]  $current_users
     * @return void
     */
    private function processUserByType($user_type, $ues_section, $users, &$current_users) {

        switch ($user_type) {
            
            case 'teacher':
                
                $this->fillUserRoleByType('teacher', $ues_section, $users, $current_users, function($user) {
                    return array('primary_flag' => $user->primary_flag);
                });
                
                break;
            
            case 'student':

                $this->fillUserRoleByType('student', $ues_section, $users, $current_users);

                break;

            default:
                # code...
                break;
        }

    }

    /**
     *
     * @param string $type 'student' or 'teacher'
     * @param ues_section $section
     * @param object[] $users
     * @param ues_student[] $current_users all users currently registered in the UES tables for this section
     * @param callback $extra_params function returning additional user parameters/fields
     * an associative array of additional params, given a user as input
     */
    private function fillUserRoleByType($type, $section, $users, &$current_users, $extra_params = null) {
        $class = 'ues_' . $type;
        $already_enrolled = array(ues::ENROLLED, ues::PROCESSED);

        foreach ($users as $user) {
            $ues_user = $this->create_user($user);

            $params = array(
                'sectionid' => $section->id,
                'userid'    => $ues_user->id
            );

            if ($extra_params) {
                // teacher-specific; returns user's primary flag key => value
                $params += $extra_params($ues_user);
            }

            $ues_type = $class::upgrade($ues_user);

            unset($ues_type->id);
            if ($prev = $class::get($params, true)) {
                $ues_type->id = $prev->id;
                unset($current_users[$prev->id]);

                // Intentionally save meta fields before continuing
                // Meta fields can change without enrollment changes
                $fields = get_object_vars($ues_type);
                if ($ues_type->params_contains_meta($fields)) {
                    $ues_type->save();
                }

                if (in_array($prev->status, $already_enrolled)) {
                    continue;
                }
            }

            $ues_type->userid = $ues_user->id;
            $ues_type->sectionid = $section->id;
            $ues_type->status = ues::PROCESSED;

            $ues_type->save();

            if (empty($prev) or $prev->status == ues::UNENROLLED) {
                // events_trigger_legacy($class . '_process', $ues_type);
                /*
                 * Refactor events_trigger_legacy
                 */
                global $CFG;
                if($class === 'student' && file_exists($CFG->dirroot.'/blocks/ues_logs/eventslib.php')){
                    ues_logs_event_handler::ues_student_process($ues_type);
                }elseif($class === 'teacher' && file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                    cps_ues_handler::ues_teacher_process($ues_type);
                }
            }
        }
    }

    




    //////////// PRIVATES //////////////////

    /**
     * Outputs a message to the console and adds it to message log
     * 
     * @param  string  $message
     * @param  boolean $addLineBreak  whether or not to add a blank line beneath this message in the console output
     * @return null
     */
    public function log($message, $addLineBreak = false) {
        
        if ( ! $this->is_silent) {
            $this->output($message, $addLineBreak);
        }

        $this->addToMessageLog($message);
    }

    /**
     * Outputs an error message to the console and adds it to the message and error logs
     * 
     * @param  string  $message
     * @param  boolean $addLineBreak  whether or not to add a blank line beneath this message in the console output
     * @return null
     */
    public function logError($message, $addLineBreak = false) {
        
        if ( ! $this->is_silent) {
            $this->output($message, $addLineBreak);
        }

        $this->addToErrorLog($message);
    }

    /**
     * Outputs a log message to the console
     * 
     * @param  string  $message
     * @param  boolean $addLineBreak  whether or not to add a blank line beneath this message in the console output
     * @return null
     */
    private function output($message, $addLineBreak = false) {
        mtrace($message);

        if ($addLineBreak)
            mtrace('');
    }

    /**
     * Adds a message to the message log array
     * 
     * @param  string  $message
     * @return null
     */
    private function addToMessageLog($message) {
        $this->messageLog[] = $message;
    }

    /**
     * Adds an error message to the error log array and, by default, adds it to the message log as well
     * 
     * @param  string   $message
     * @param  boolean  $addToMessageLog
     * @return null
     */
    private function addToErrorLog($message, $addToMessageLog = true) {
        $this->errorLog[] = $message;
        
        if ($addToMessageLog)
            $this->messageLog[] = 'ERROR: ' . $message;
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






    //////////// NOT YET USED //////////////////


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

    public function handle_enrollments() {
        // will be unenrolled
        $pending = ues_section::get_all(array('status' => ues::PENDING));
        $this->handle_pending_sections($pending);

        // will be enrolled
        $processed = ues_section::get_all(array('status' => ues::PROCESSED));
        $this->handle_processed_sections($processed);
    }

    

    private function process_semester_by_section($semester, $courses) {
        foreach ($courses as $course) {
            foreach ($course->sections as $section) {
                $ues_section = ues_section::by_id($section->id);
                $this->process_enrollment(
                    $semester, $course, $ues_section
                );
            }
        }
    }

    /**
     * Could be used to process a single course upon request
     */
    public function process_enrollment($semester, $course, $section) {
        $teacher_source = $this->provider()->teacher_source();

        $student_source = $this->provider()->student_source();

        try {
            $teachers = $teacher_source->teachers($semester, $course, $section);
            $students = $student_source->students($semester, $course, $section);

            $filter = array('sectionid' => $section->id);
            $current_teachers = ues_teacher::get_all($filter);
            $current_students = ues_student::get_all($filter);

            

            // $this->process_teachers($section, $teachers, $current_teachers);
            // changed to:
            $this->processUserByType('teacher', $section, $teachers, $current_teachers);
            // $this->process_students($section, $students, $current_students);
            // changed to:
            $this->processUserByType('student', $section, $students, $current_students);

            

            $this->release('teacher', $current_teachers);
            $this->release('student', $current_students);

            $this->processSectionEnrollment($semester, $course, $section);
        } catch (Exception $e) {
            $this->logError($e->getMessage());

            ues_error::section($section)->save();
        }
    }

    private function release($type, $users) {

        foreach ($users as $user) {
            // No reason to release a second time
            if ($user->status == ues::UNENROLLED) {
                continue;
            }

            // Maybe the course hasn't been created... clear the pending flag
            $status = $user->status == ues::PENDING ? ues::UNENROLLED : ues::PENDING;

            $user->status = $status;
            $user->save();

            global $CFG;
            if($type === 'teacher'){
                if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                    require_once $CFG->dirroot.'/blocks/cps/events/ues.php';

                    // Specific release for instructor
                    //events_trigger_legacy('ues_teacher_release', $user);
                    $user = cps_ues_handler::ues_teacher_release($user);
                }
            }else if($type === 'student'){
                if(file_exists($CFG->dirroot.'/blocks/ues_logs/eventslib.php')){
                    require_once $CFG->dirroot.'/blocks/ues_logs/eventslib.php';
                    $user = ues_logs_event_handler::ues_student_release($user);
                }

            }

            // Drop manifested sections for teacher POTENTIAL drops
            if ($user->status == ues::PENDING and $type == 'teacher') {
                $existing = ues_teacher::get_all(ues::where()
                    ->status->in(ues::PROCESSED, ues::ENROLLED)
                );

                // No other primary, so we can safely flip the switch
                if (empty($existing)) {
                    ues_section::update(
                        array('status' => ues::PENDING),
                        array(
                            'status' => ues::MANIFESTED,
                            'id' => $user->sectionid
                        )
                    );
                }
            }
        }
    }

    

    // Allow public API to reset unenrollments
    public function reset_unenrollments($section) {
        $course = $section->moodle();

        // Nothing to do
        if (empty($course)) {
            return;
        }

        $ues_course = $section->course();

        foreach (array('student', 'teacher') as $type) {
            $group = $this->manifest_group($course, $ues_course, $section);

            $class = 'ues_' . $type;

            $params = array(
                'sectionid' => $section->id,
                'status' => ues::UNENROLLED
            );

            $users = $class::get_all($params);
            $this->unenroll_users($group, $users);
        }
    }

    /**
     * Unenroll courses/sections.
     *
     * Given an input array of ues_sections, remove them and their enrollments
     * from active status.
     * If the section is not manifested, set its status to ues::SKIPPED.
     * If it has been manifested, get a reference to the Moodle course.
     * Get the students and teachers enrolled in the course and unenroll them.
     * Finally, set the idnumber to the empty string ''.
     *
     * In addition, we will @see events_trigger TRIGGER EVENT 'ues_course_severed'.
     *
     * @global object $DB
     * @param ues_section[] $sections
     */
    public function handle_pending_sections($sections) {
        global $DB, $USER;

        if ($sections) {
            $this->log('Found ' . count($sections) . ' Sections that will not be manifested.');
        }

        foreach ($sections as $section) {
            if ($section->is_manifested()) {

                $params = array('idnumber' => $section->idnumber);

                $course = $section->moodle();

                $ues_course = $section->course();

                foreach (array('student', 'teacher') as $type) {

                    $group = $this->manifest_group($course, $ues_course, $section);

                    $class = 'ues_' . $type;

                    $params = ues::where()
                        ->sectionid->equal($section->id)
                        ->status->in(ues::ENROLLED, ues::UNENROLLED);

                    $users = $class::get_all($params);
                    $this->unenroll_users($group, $users);
                }

                // set course visibility according to user preferences (block_cps)
                $setting_params = ues::where()
                    ->userid->equal($USER->id)
                    ->name->starts_with('creation_');

                $settings        = cps_setting::get_to_name($setting_params);
                $setting         = !empty($settings['creation_visible']) ? $settings['creation_visible'] : false;

                $course->visible = isset($setting->value) ? $setting->value : get_config('moodlecourse', 'visible');

                $DB->update_record('course', $course);

                $this->log('Unloading ' . $course->idnumber);

                //events_trigger_legacy('ues_course_severed', $course);
                /**
                 * Refactor events_trigger_legacy().
                 */
                global $CFG;
                if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                    require_once $CFG->dirroot.'/blocks/cps/events/ues.php';
                    cps_ues_handler::ues_course_severed($course);
                }

                $section->idnumber = '';
            }
            $section->status = ues::SKIPPED;
            $section->save();
        }

        $this->log('');
    }

    /**
     * Handle courses to be manifested.
     *
     * For each incoming section, manifest the course and update its status to
     * ues::Manifested.
     *
     * Skip any incoming section whose status is ues::PENDING.
     *
     * @param ues_section[] $sections
     */
    public function handle_processed_sections($sections) {
        if ($sections) {
            $this->log('Found ' . count($sections) . ' Sections ready to be manifested.');
        }

        foreach ($sections as $section) {
            if ($section->status == ues::PENDING) {
                continue;
            }

            $semester = $section->semester();

            $course = $section->course();

            $success = $this->manifestation($semester, $course, $section);

            if ($success) {
                $section->status = ues::MANIFESTED;
                $section->save();
            }
        }

        $this->log('');
    }

    public function get_instance($courseid) {
        global $DB;

        $instances = enrol_get_instances($courseid, true);

        $attempt = array_filter($instances, function($in) {
            return $in->enrol == 'ues';
        });

        // Cannot enrol without an instance
        if (empty($attempt)) {
            $course_params = array('id' => $courseid);
            $course = $DB->get_record('course', $course_params);

            $id = $this->add_instance($course);

            return $DB->get_record('enrol', array('id' => $id));
        } else {
            return current($attempt);
        }
    }

    public function manifest_category($course) {
        global $DB;

        $cat_params = array('name' => $course->department);
        $category = $DB->get_record('course_categories', $cat_params);

        if (!$category) {
            $category = new stdClass;

            $category->name = $course->department;
            $category->sortorder = 999;
            $category->parent = 0;
            $category->description = 'Courses under ' . $course->department;
            $category->id = $DB->insert_record('course_categories', $category);
        }

        return $category;
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
     * @see ues_enrol_plugin::manifest_course
     * @see ues_enrol_plugin::manifest_course_enrollment
     * @event ues_primary_change
     * @param ues_semester $semester
     * @param ues_course $course
     * @param ues_section $section
     * @return boolean
     */
    private function manifestation($semester, $course, $section) {
        // Check for instructor changes
        $teacher_params = array(
            'sectionid' => $section->id,
            'primary_flag' => 1
        );

        $new_primary = ues_teacher::get($teacher_params + array(
            'status' => ues::PROCESSED
        ));

        $old_primary = ues_teacher::get($teacher_params + array(
            'status' => ues::PENDING
        ));

        //if there's no old primary, check to see if there's an old non-primary
        if(!$old_primary){
            $old_primary = ues_teacher::get(array(
                'sectionid'    => $section->id,
                'status'       => ues::PENDING,
                'primary_flag' => 0
            ));

            // if this is the same user getting a promotion, no need to unenroll the course...
            if($old_primary){
                $old_primary = $old_primary->userid == $new_primary->userid ? false : $old_primary;
            }
        }


        // Campuses may want to handle primary instructor changes differently
        if ($new_primary and $old_primary) {

            global $DB;
            $new = $DB->get_record('user',array('id'=>$new_primary->userid));
            $old = $DB->get_record('user',array('id'=>$old_primary->userid));
            $this->log(sprintf("instructor change from %s to %s\n", $old->username, $new->username));

            $data = new stdClass;
            $data->section = $section;
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

            $section = $data->section;
        }

        // For certain we are working with a real course
        $moodle_course = $this->manifest_course($semester, $course, $section);

        $this->manifest_course_enrollment($moodle_course, $course, $section);

        return true;
    }

    /**
     * Manifest enrollment for a given course section
     * Fetches a group using @see enrol_ues_plugin::manifest_group(),
     * fetches all teachers, students that belong to the group/section
     * and enrolls/unenrolls via @see enrol_ues_plugin::enroll_users() or @see unenroll_users()
     *
     * @param object $moodle_course object from {course}
     * @param ues_course $course object from {enrol_ues_courses}
     * @param ues_section $section object from {enrol_ues_sections}
     */
    private function manifest_course_enrollment($moodle_course, $course, $section) {
        $group = $this->manifest_group($moodle_course, $course, $section);

        $general_params = array('sectionid' => $section->id);

        $actions = array(
            ues::PROCESSED => 'enroll',
            ues::PENDING => 'unenroll'
        );

        $unenroll_count = $enroll_count = 0;

        foreach (array('teacher', 'student') as $type) {
            $class = 'ues_' . $type;

            foreach ($actions as $status => $action) {
                $action_params = $general_params + array('status' => $status);
                ${$action . '_count'} = $class::count($action_params);

                if (${$action . '_count'}) {
                    // This will only happen if there are no more
                    // teachers and students are set to be enrolled
                    // We should log it as a potential error and continue.
                    try {

                        $to_action = $class::get_all($action_params);
                        $this->{$action . '_users'}($group, $to_action);
                    } catch (Exception $e) {
                        $this->logError(ues::_s('error_no_group', $group));
                    }
                }
            }
        }

        if ($unenroll_count or $enroll_count) {
            $this->log('Manifesting enrollment for: ' . $moodle_course->idnumber .
            ' ' . $section->sec_number);

            $out = '';
            if ($unenroll_count) {
                $out .= 'Unenrolled ' . $unenroll_count . ' users; ';
            }

            if ($enroll_count) {
                $out .= 'Enrolled ' . $enroll_count . ' users';
            }

            $this->log($out);
        }
    }

    private function enroll_users($group, $users) {
        $instance = $this->get_instance($group->courseid);

        // Pull this setting once
        $recover = $this->config('recover_grades');

        // Require check once
        if ($recover and !function_exists('grade_recover_history_grades')) {
            global $CFG;
            require_once $CFG->libdir . '/gradelib.php';
        }

        $recover_grades_for = function($user) use ($recover, $instance) {
            if ($recover) {
                grade_recover_history_grades($user->userid, $instance->courseid);
            }
        };

        foreach ($users as $user) {
            $shortname = $this->determine_role($user);
            $roleid = $this->config($shortname . '_role');

            $this->enrol_user($instance, $user->userid, $roleid);

            groups_add_member($group->id, $user->userid);

            $recover_grades_for($user);

            $user->status = ues::ENROLLED;
            $user->save();

            $event_params = array(
                'group' => $group,
                'ues_user' => $user
            );

            // Unmonitored event.
            //events_trigger_legacy('ues_' . $shortname . '_enroll', $event_params);
        }
    }

    private function unenroll_users($group, $users) {
        global $DB;


        $instance = $this->get_instance($group->courseid);

        $course = $DB->get_record('course', array('id' => $group->courseid));

        foreach ($users as $user) {
            $shortname = $this->determine_role($user);

            $class = 'ues_' . $shortname;

            $roleid = $this->config($shortname . '_role');

            // Ignore pending statuses for users who have no role assignment
            $context = context_course::instance($course->id);
            if (!is_enrolled($context, $user->userid)) {
                continue;
            }

            groups_remove_member($group->id, $user->userid);

            // Don't mark those meat to be unenrolled to processed
            $prev_status = $user->status;

            $to_status = (
                $user->status == ues::PENDING or
                $user->status == ues::UNENROLLED
            ) ?
                ues::UNENROLLED :
                ues::PROCESSED;

            $user->status = $to_status;
            $user->save();

            $sections = $user->sections_by_status(ues::ENROLLED);

            $is_enrolled = false;
            $same_section = false;

            foreach ($sections as $section) {
                if ($section->idnumber == $course->idnumber) {
                    $is_enrolled = true;
                }

                // This user is enroll as another role in the same section
                if ($section->id == $user->sectionid) {
                    $same_section = true;
                }
            }

            // This user is enrolled as another role (teacher) in the same
            // section so keep groups alive
            if (!$is_enrolled) {
                $this->unenrol_user($instance, $user->userid, $roleid);

            } else if ($same_section) {
                groups_add_member($group->id, $user->userid);
            }

            if ($to_status != $prev_status and $to_status == ues::UNENROLLED) {
                $event_params = array(
                    'group' => $group,
                    'ues_user' => $user
                );

                // Unmonitored event.
                //events_trigger_legacy('ues_' . $shortname . '_unenroll', $event_params);
            }

        }

        $count_params = array('groupid' => $group->id);
        if (!$DB->count_records('groups_members', $count_params)) {
            // Going ahead and delete as delete
            groups_delete_group($group);

            // No-op event.
            // @see cps_ues_handler::ues_group_emptied()
            // events_trigger_legacy('ues_group_emptied', $group);
        }
    }

    /**
     * Fetches existing or creates new group based on given params
     * @global type $DB
     * @param stdClass $moodle_course object from {course}
     * @param ues_course $course object from {enrol_ues_courses}
     * @param ues_section $section object from {enrol_ues_sections}
     * @return stdClass object from {groups}
     */
    private function manifest_group($moodle_course, $course, $section) {
        global $DB;

        $group_params = array(
            'courseid' => $moodle_course->id,
            'name' => "{$course->department} {$course->cou_number} {$section->sec_number}"
        );

        if (!$group = $DB->get_record('groups', $group_params)) {
            $group = (object) $group_params;
            $group->id = groups_create_group($group);
        }

        return $group;
    }

    private function manifest_course($semester, $course, $section) {
        global $DB;
        $primary_teacher = $section->primary();

        if (!$primary_teacher) {

            $primary_teacher = current($section->teachers()); //arbitrary ? send email notice
        }

        $assumed_idnumber = $semester->year . $semester->name .
            $course->department . $semester->session_key . $course->cou_number .
            $primary_teacher->userid;

        // Take into consideration of outside forces manipulating idnumbers
        // Therefore we must check the section's idnumber before creating one
        // Possibility the course was deleted externally

        $idnumber = !empty($section->idnumber) ? $section->idnumber : $assumed_idnumber;

        $course_params = array('idnumber' => $idnumber);

        $moodle_course = $DB->get_record('course', $course_params);

        // Handle system creation defaults
        $settings = array(
            'visible','format','lang','groupmode','groupmodeforce', 'hiddensections',
            'newsitems','showgrades','showreports','maxbytes','enablecompletion',
            'completionstartonenrol','numsections', 'legacyfiles'
        );

        if (!$moodle_course) {
            $user = $primary_teacher->user();

            $session = empty($semester->session_key) ? '' :
                '(' . $semester->session_key . ') ';

            $category = $this->manifest_category($course);

            $a = new stdClass;
            $a->year = $semester->year;
            $a->name = $semester->name;
            $a->session = $session;
            $a->department = $course->department;
            $a->course_number = $course->cou_number;
            $a->fullname = fullname($user);
            $a->userid = $user->id;

            $sn_pattern = $this->config('course_shortname');
            $fn_pattern = $this->config('course_fullname');

            $shortname = ues::format_string($sn_pattern, $a);
            $assumed_fullname = ues::format_string($fn_pattern, $a);

            $moodle_course = new stdClass;
            $moodle_course->idnumber = $idnumber;
            $moodle_course->shortname = $shortname;
            $moodle_course->fullname = $assumed_fullname;
            $moodle_course->category = $category->id;
            $moodle_course->summary = $course->fullname;
            $moodle_course->startdate = $semester->classes_start;

            // Set system defaults
            foreach ($settings as $key) {
                $moodle_course->$key = get_config('moodlecourse', $key);
            }

            // Actually needs to happen, before the create call
            //events_trigger_legacy('ues_course_create', $moodle_course);
            /*
             * Refactor events_trigger_legacy call
             */
            global $CFG;
            if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                require_once $CFG->dirroot.'/blocks/cps/events/ues.php';
                $moodle_course = cps_ues_handler::ues_course_create($moodle_course);
            }

            try {
                $moodle_course = create_course($moodle_course);

                $this->add_instance($moodle_course);
            } catch (Exception $e) {
                $this->logError(ues::_s('error_shortname', $moodle_course));

                $course_params = array('shortname' => $moodle_course->shortname);
                $idnumber = $moodle_course->idnumber;

                $moodle_course = $DB->get_record('course', $course_params);
                $moodle_course->idnumber = $idnumber;

                if (!$DB->update_record('course', $moodle_course)) {
                    $this->logError('Could not update course: ' . $moodle_course->idnumber);
                }
            }
        }

        if (!$section->idnumber) {
            $section->idnumber = $moodle_course->idnumber;
            $section->save();
        }

        return $moodle_course;
    }

    /**
     * Triggers an event, 'user_updated' which is consumed by block_cps
     * and other. The badgeslib.php event handler uses a run-time type
     * hint which caused problems for ues enrollment runEnrollmentProcess()
     * causes problems
     *
     * @todo fix badgeslib issue
     * @global type $CFG
     * @param type $u
     *
     * @return ues_user $user
     * @throws Exception
     */
    private function create_user($u) {
        $present = !empty($u->idnumber);

        $by_idnumber = array('idnumber' => $u->idnumber);

        $by_username = array('username' => $u->username);

        $exact_params = $by_idnumber + $by_username;

        $user = ues_user::upgrade($u);

        if ($prev = ues_user::get($exact_params, true)) {
            $user->id = $prev->id;
        } else if ($present and $prev = ues_user::get($by_idnumber, true)) {
            $user->id = $prev->id;
            // Update email
            $user->email = $user->username . $this->config('user_email');
        } else if ($prev = ues_user::get($by_username, true)) {
            $user->id = $prev->id;
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

        if (!empty($created)) {
            $user->save();

            events_trigger_legacy('user_created', $user);
        } else if ($prev and $this->user_changed($prev, $user)) {
            // Re-throw exception with more helpful information
            try {
                $user->save();
            } catch (Exception $e) {
                $rea = $e->getMessage();

                $new_err = "%s | Current %s | Stored %s";
                $log = "(%s: '%s')";

                $curr = sprintf($log, $user->username, $user->idnumber);
                $prev = sprintf($log, $prev->username, $prev->idnumber);

                throw new Exception(sprintf($new_err, $rea, $curr, $prev));
            }

            // Unmonitored event
            // events_trigger_legacy('user_updated', (object)$user);
        }

        // If the provider supplies initial password information, set it now.
        if(isset($user->auth) and $user->auth === 'manual' and isset($user->init_password)){
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
     *
     * @global object $DB
     * @param ues_user $prev these var names are misleading: $prev is the user
     * 'previously' stored in the DB- that is the current DB record for a user.
     * @param ues_user $current Also a tad misleading, $current repressents the
     * incoming user currently being evaluated at this point in the UES process.
     * Depending on the outcome of this function, current's data may or may not ever
     * be used of stored.
     * @return boolean According to our comparissons, does current hold new information
     * for a previously stored user that we need to replace the DB record with [replacement
     * happens in the calling function]?
     */
    private function user_changed(ues_user $prev, ues_user $current) {
        global $DB;
        $namefields   = user_picture::fields();
        $sql          = "SELECT id, idnumber, $namefields FROM {user} WHERE id = :id";

        // @TODO ues_user does not currently upgrade with the alt names.
        $previoususer = $DB->get_record_sql($sql, array('id'=>$prev->id));

        // Need to establish which users have preferred names.
        $haspreferredname            = !empty($previoususer->alternatename);

        // For users without preferred names, check that old and new firstnames match.
        // No need to take action, if true.
        $reguser_firstnameunchanged  = !$haspreferredname && $previoususer->firstname == $current->firstname;

        // For users with preferred names, check that old altname matches incoming firstname.
        // No need to take action, if true.
        $prefuser_firstnameunchanged = $haspreferredname && $previoususer->alternatename == $current->firstname;

        // Composition of the previous two variables. If either if false,
        // we need to take action and return 'true'.
        $firstnameunchanged          = $reguser_firstnameunchanged || $prefuser_firstnameunchanged;

        // We take action if last name has changed at all.
        $lastnameunchanged           = $previoususer->lastname == $current->lastname;

        // If there is change in either first or last, we are going to update the user DB record.
        if(!$firstnameunchanged || !$lastnameunchanged){
            // When the first name of a user who has set a preferred
            // name changes, we reset the preference in CPS.
            if(!$prefuser_firstnameunchanged){
                $DB->set_field('user', 'alternatename', NULL, array('id'=>$previoususer->id));

                //events_trigger_legacy('preferred_name_legitimized', $current);
                /*
                 * Refactor events_trigger_legacy
                 */
                global $CFG;
                if(file_exists($CFG->dirroot.'/blocks/cps/events/ues.php')){
                    require_once $CFG->dirroot.'/blocks/cps/events/ues.php';
                    cps_ues_handler::preferred_name_legitimized($current);
                }
            }
            return true;
        }

        if ($prev->idnumber != $current->idnumber){
            return true;
        }

        if ($prev->username != $current->username){
            return true;
        }

        $current_meta = $current->meta_fields(get_object_vars($current));

        foreach ($current_meta as $field) {
            if (!isset($prev->{$field})){
                return true;
            }

            if ($prev->{$field} != $current->{$field}){
                return true;
            }
        }
        return false;
    }

    /**
     * determine a user's role based on the presence and setting
     * of a a field primary_flag
     * @param type $user
     * @return string editingteacher | teacher | student
     */
    private function determine_role($user) {
        if (isset($user->primary_flag)) {
            $role = $user->primary_flag ? 'editingteacher' : 'teacher';
        } else {
            $role = 'student';
        }
        return $role;
    }

    public function email_reports() {
        global $CFG;

        return true; // @TODO - for testing only, remove this!

        $admins = get_admins();

        if ($this->config('email_report')) {
            $email_text = implode("\n", $this->messageLog);

            foreach ($admins as $admin) {
                email_to_user($admin, ues::_s('pluginname'),
                    sprintf('UES Log [%s]', $CFG->wwwroot), $email_text);
            }
        }

        if (!empty($this->errorLog)) {
            $error_text = implode("\n", $this->errorLog);

            foreach ($admins as $admin) {
                email_to_user($admin, ues::_s('pluginname'),
                    sprintf('[SEVERE] UES Errors [%s]', $CFG->wwwroot), $error_text);
            }
        }
    }

    private function handle_automatic_errors() {
        $errors = ues_error::get_all();

        // don't reprocess if the module is running
        $running = (bool)$this->config('running');

        if ($running) {
            global $CFG;
            $url = $CFG->wwwroot . '/admin/settings.php?section=enrolsettingsues';
            $this->logError(ues::_s('already_running', $url));
            return;
        }

        $error_threshold = $this->config('error_threshold');

        // don't reprocess if there are too many errors
        if (count($errors) > $error_threshold) {
            $this->logError(ues::_s('error_threshold_log'));
            return;
        }

        ues::reprocess_errors($errors, true);
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
