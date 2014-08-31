<?php

/**
 * @package enrol_ues
 */
defined('MOODLE_INTERNAL') or die();

require_once dirname(__FILE__) . '/publiclib.php';

class enrol_ues_plugin extends enrol_plugin {

    /**
     * Typical errorlog for cron run
     * @todo remove 'var' keyword; make private.
     * @var array
     */
    var $errors = array();

    /**
     * Typical email log for cron runs
     * @todo remove 'var' keyword; make private
     * @var array
     */
    var $emaillog = array();

    /**
     * @todo remove the 'var' keyword, replace with 'private'
     * @var bool admin config setting
     */
    var $is_silent = false;

    /**
     * an instance of the ues enrollment provider.
     *
     * Provider is configured in admin settings.
     *
     * @var enrollment_provider $_provider
     */
    private $_provider;

    /**
     * Provider initialization status.
     *
     * @var bool
     */
    private $_loaded = false;

    /**
     * Require internal and External libs.
     *
     * @global object $CFG
     */
    public function __construct() {
        global $CFG;

        $lib = ues::base('classes/dao');//@todo: remove this; not used;

        ues::require_daos();
        require_once $CFG->dirroot . '/group/lib.php';
        require_once $CFG->dirroot . '/course/lib.php';
    }

    /**
     * Try to initialize the provider.
     *
     * Tries to create and initialize the provider.
     * Tests whether provider supports departmental or section lookups.
     * @throws Exception if provider cannot be created of if provider supports
     * neither section nor department lookups.
     */
    public function init() {
        $this->_loaded = true;

        try {
            $this->_provider = ues::create_provider();

            if (empty($this->_provider)) {
                throw new Exception('enrollment_unsupported');
            }

            $works = (
                $this->_provider->supports_section_lookups() or
                $this->_provider->supports_department_lookups()
            );

            if ($works === false) {
                throw new Exception('enrollment_unsupported');
            }
        } catch (Exception $e) {
            $a = ues::translate_error($e);
            $this->errors[] = ues::_s('provider_cron_problem', $a);
        }
    }

    /**
     * Getter for self::$_provider.
     *
     * If self::$provider is not set already, this method
     * will attempt to initialize it by calling self::init()
     * before returning the value of self::$_provider
     * @return enrollment_provider
     */
    public function provider() {
        if (empty($this->_provider) and !$this->_loaded) {
            $this->init();
        }

        return $this->_provider;
    }

    public function course_updated($inserted, $course, $data) {
        // UES is the one to create the course
        if ($inserted) {
            return;
        }

        // Delete extension handler
        events_trigger_legacy('ues_course_updated', array($course, $data));
    }

    public function course_edit_validation($instance, array $data, $context) {
        $errors = array();
        if (is_null($instance)) {
            return $errors;
        }

        $system = context_system::instance();
        $can_change = has_capability('moodle/course:update', $system);

        $restricted = explode(',', $this->setting('course_restricted_fields'));

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

        events_trigger_legacy('ues_course_edit_validation', $event);

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

        events_trigger_legacy('ues_course_edit_form', $event);
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
            if ($this->setting('course_form_replace')) {
                $url = new moodle_url(
                    '/enrol/ues/edit.php',
                    array('id' => $instance->courseid)
                );
                $nodes->parent->parent->get('editsettings')->action = $url;
            }
	}

        // Allow outside interjection
        $params = array($nodes, $instance);
        events_trigger_legacy('ues_course_settings_navigation', $params);
    }

    public function is_cron_required() {

        $automatic = $this->setting('cron_run');

        $running = (bool)$this->setting('running');

        if ($automatic) {

            $this->handle_automatic_errors();

            $current_hour = (int)date('H');

            $acceptable_hour = (int)$this->setting('cron_hour');

            $right_time = ($current_hour == $acceptable_hour);

            // Grace period from last started job
            $starttime = (int)$this->setting('starttime');
            $grace_period = (int)$this->setting('grace_period');

            $ran_more_than_hour_ago = (time() - $starttime) > $grace_period;

            $is_late = ($running and $ran_more_than_hour_ago);

            $is_supposed_to_run = ($right_time and parent::is_cron_required());

            if ($is_late and $is_supposed_to_run) {

                global $CFG;
                $url = $CFG->wwwroot . '/admin/settings.php?section=enrolsettingsues';
                $this->errors[] = ues::_s('already_running', $url);

                $this->email_reports();
                return false;
            }

            return (
                $right_time and
                parent::is_cron_required() and
                !$running
            );
        }

        return !$running;
    }

    private function handle_automatic_errors() {
        $errors = ues_error::get_all();

        $error_threshold = $this->setting('error_threshold');

        $running = (bool)$this->setting('running');

        // Don't reprocess if the module is running
        if ($running) {
            return;
        }

        if (count($errors) > $error_threshold) {
            $this->errors[] = ues::_s('error_threshold_log');
            return;
        }

        ues::reprocess_errors($errors, true);
    }

    public function cron() {
        $this->setting('running', true);

        $this->setting('starttime', time());
        if ($this->provider()) {
            $this->log('------------------------------------------------');
            $this->log(ues::_s('pluginname'));
            $this->log('------------------------------------------------');

            $start = microtime();

            $this->full_process();

            $end = microtime();

            $how_long = microtime_diff($start, $end);

            $this->log('------------------------------------------------');
            $this->log('UES enrollment took: ' . $how_long . ' secs');
            $this->log('------------------------------------------------');
        }

        $this->email_reports();

        $this->setting('running', false);
    }

    public function email_reports() {
        global $CFG;

        $admins = get_admins();

        if ($this->setting('email_report') and !empty($this->emaillog)) {
            $email_text = implode("\n", $this->emaillog);

            foreach ($admins as $admin) {
                email_to_user($admin, ues::_s('pluginname'),
                    sprintf('UES Log [%s]', $CFG->wwwroot), $email_text);
            }
        }

        if (!empty($this->errors)) {
            $error_text = implode("\n", $this->errors);

            foreach ($admins as $admin) {
                email_to_user($admin, ues::_s('pluginname'),
                    sprintf('[SEVERE] UES Errors [%s]', $CFG->wwwroot), $error_text);
            }
        }
    }

    public function setting($key, $value = null) {
        if ($value !== null) {
            return set_config($key, $value, 'enrol_ues');
        } else {
            return get_config('enrol_ues', $key);
        }
    }

    /**
     * top-level fn called by cron
     * executes pre-process, process and post-process phases
     */
    public function full_process() {

        if (!$this->provider()->preprocess($this)) {
            $this->errors[] = 'Error during preprocess.';
        }

        $provider_name = $this->provider()->get_name();
        $this->log('Pulling information from ' . $provider_name);
        $this->process_all();
        $this->log('------------------------------------------------');

        $this->log('Begin manifestation ...');
        $this->handle_enrollments();

        if (!$this->provider()->postprocess($this)) {
            $this->errors[] = 'Error during postprocess.';
        }
    }


    public function handle_enrollments() {
        // will be unenrolled
        $pending = ues_section::get_all(array('status' => ues::PENDING));
        $this->handle_pending_sections($pending);

        // will be enrolled
        $processed = ues_section::get_all(array('status' => ues::PROCESSED));
        $this->handle_processed_sections($processed);
    }

    /**
     * Get (fetch, instantiate, save) semesters 
     * considered valid at the current time, and
     * process enrollment for each.
     */
    public function process_all() {
        $time = time();

        $processed_semesters = $this->get_semesters($time);

        foreach ($processed_semesters as $semester) {
            $this->process_semester($semester);
        }
    }

    /**
     * @param ues_semester[] $semester
     */
    public function process_semester($semester) {
        $process_courses = $this->get_courses($semester);

        if (empty($process_courses)) {
            return;
        }

        $set_by_department   = (bool) $this->setting('process_by_department');

        $supports_department = $this->provider()->supports_department_lookups();

        $supports_section    = $this->provider()->supports_section_lookups();

        if ($set_by_department and $supports_department) {
            $this->process_semester_by_department($semester, $process_courses);
        } else if (!$set_by_department and $supports_section) {
            $this->process_semester_by_section($semester, $process_courses);
        } else{
            $message = ues::_s('could_not_enroll', $semester);

            $this->log($message);
            $this->errors[] = $message;
        }
    }

    /**
     * @param ues_semester $semester
     * @param ues_course[] $courses NB: must have department attribute set
     */
    private function process_semester_by_department($semester, $courses) {
        $departments = ues_course::flatten_departments($courses);

        foreach ($departments as $department => $courseids) {
            $filters = ues::where()
                ->semesterid->equal($semester->id)
                ->courseid->in($courseids);

            //'current' means they already exist in the DB
            $current_sections = ues_section::get_all($filters);

            $this->process_enrollment_by_department(
                $semester, $department, $current_sections
            );
        }
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
     * From enrollment provider, get, instantiate, 
     * save (to {enrol_ues_semesters}) and return all valid semesters.
     * @param int time
     * @return ues_semester[] these objects will be later upgraded to ues_semesters
     * 
     */
    public function get_semesters($time) {
        $set_days = (int) $this->setting('sub_days');
        $sub_days = 24 * $set_days * 60 * 60;

        $now = ues::format_time($time - $sub_days);

        $this->log('Pulling Semesters for ' . $now . '...');

        try {
            $semester_source = $this->provider()->semester_source();
            $semesters = $semester_source->semesters($now);
            $this->log('Processing ' . count($semesters) . " Semesters...\n");
            $p_semesters = $this->process_semesters($semesters);

            $v = function($s) {
                return !empty($s->grades_due);
            };

            $i = function($s) {
                return !empty($s->semester_ignore);
            };

            list($other, $failures) = $this->partition($p_semesters, $v);

            // Notify improper semester
            foreach ($failures as $failed_sem) {
                $this->errors[] = ues::_s('failed_sem', $failed_sem);
            }

            list($ignored, $valids) = $this->partition($other, $i);

            // Ignored sections with semesters will be unenrolled
            foreach ($ignored as $ignored_sem) {
                $where_manifested = ues::where()
                    ->semesterid->equal($ignored_sem->id)
                    ->status->equal(ues::MANIFESTED);

                $to_drop = array('status' => ues::PENDING);

                // This (what? Pending status?) will be caught in regular process
                ues_section::update($to_drop, $where_manifested);
            }

            $sems_in = function ($sem) use ($time, $sub_days) {
                $end_check = $time < $sem->grades_due;

                return ($sem->classes_start - $sub_days) < $time && $end_check;
            };

            return array_filter($valids, $sems_in);
        } catch (Exception $e) {

            $this->errors[] = $e->getMessage();
            return array();
        }
    }

    public function partition($collection, $func) {
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
     * Fetch courses from the enrollment provider, and pass them to 
     * process_courses() for instantiations as ues_course objects and for 
     * persisting to {enrol_ues(_courses|_sections)}.
     * 
     * @param ues_semester $semester
     * @return ues_course[]
     */
    public function get_courses($semester) {
        $this->log('Pulling Courses / Sections for ' . $semester);
        try {
            $courses = $this->provider()->course_source()->courses($semester);

            $this->log('Processing ' . count($courses) . " Courses...\n");
            $process_courses = $this->process_courses($semester, $courses);

            return $process_courses;
        } catch (Exception $e) {
            $this->errors[] = sprintf(
                    'Unable to process courses for %s; Message was: %s',
                    $semester,
                    $e->getMessage()
                    );

            // Queue up errors
            ues_error::courses($semester)->save();

            return array();
        }
    }

    /**
     * Workhorse method that brings enrollment data from the provider together with existing records
     * and then dispatches sub processes that operate on the differences between the two.
     * 
     * @param ues_semester $semester semester to process
     * @param string $department department to process
     * @param ues_section[] $current_sections current UES records for the department/semester combination
     */
    public function process_enrollment_by_department($semester, $department, $current_sections) {
        try {

            $teacher_source = $this->provider()->teacher_department_source();
            $student_source = $this->provider()->student_department_source();

            $teachers = $teacher_source->teachers($semester, $department);
            $students = $student_source->students($semester, $department);

            $sectionids = ues_section::ids_by_course_department($semester, $department);

            $filter = ues::where('sectionid')->in($sectionids);
            $current_teachers = ues_teacher::get_all($filter);
            $current_students = ues_student::get_all($filter);

            $ids_param    = ues::where('id')->in($sectionids);
            $all_sections = ues_section::get_all($ids_param);

            $this->process_teachers_by_department($semester, $department, $teachers, $current_teachers);
            $this->process_students_by_department($semester, $department, $students, $current_students);

            unset($current_teachers);
            unset($current_students);

            foreach ($current_sections as $section) {
                $course = $section->course();
                // Set status to ues::PROCESSED.
                $this->post_section_process($semester, $course, $section);

                unset($all_sections[$section->id]);
            }

            // Drop remaining sections.
            if (!empty($all_sections)) {
                ues_section::update(
                    array('status' => ues::PENDING),
                    ues::where('id')->in(array_keys($all_sections))
                );
            }

        } catch (Exception $e) {

            $info = "$semester $department";

            $message = sprintf(
                    "Message: %s\nFile: %s\nLine: %s\nTRACE:\n%s\n",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                    );
            $this->errors[] = sprintf('Failed to process %s:\n%s', $info, $message);

            ues_error::department($semester, $department)->save();
        }
    }

    /**
     * 
     * @param ues_semester $semester
     * @param string $department
     * @param object[] $teachers
     * @param ues_teacher[] $current_teachers
     */
    public function process_teachers_by_department($semester, $department, $teachers, $current_teachers) {
        $this->fill_roles_by_department('teacher', $semester, $department, $teachers, $current_teachers);
    }

    /**
     * 
     * @param ues_semester $semester
     * @param string $department
     * @param object[] $students
     * @param ues_student[] $current_students
     */
    public function process_students_by_department($semester, $department, $students, $current_students) {
        $this->fill_roles_by_department('student', $semester, $department, $students, $current_students);
    }

    /**
     * 
     * @param string $type @see process_teachers_by_department 
     * and @see process_students_by_department for possible values 'student'
     * or 'teacher'
     * @param ues_section $semester
     * @param string $department
     * @param object[] $pulled_users incoming users from the provider
     * @param ues_teacher[] | ues_student[] $current_users all UES users for this semester
     */
    private function fill_roles_by_department($type, $semester, $department, $pulled_users, $current_users) {
        foreach ($pulled_users as $user) {
            $course_params = array(
                'department' => $department,
                'cou_number' => $user->cou_number
            );

            $course = ues_course::get($course_params);

            if (empty($course)) {
                continue;
            }

            $section_params = array(
                'semesterid' => $semester->id,
                'courseid'   => $course->id,
                'sec_number' => $user->sec_number
            );

            $section = ues_section::get($section_params);

            if (empty($section)) {
                continue;
            }
            $this->{'process_'.$type.'s'}($section, array($user), $current_users);

        }

        $this->release($type, $current_users);
    }

    /**
     * 
     * @param stdClass[] $semesters
     * @return ues_semester[]
     */
    public function process_semesters($semesters) {
        $processed = array();

        foreach ($semesters as $semester) {
            try {
                $params = array(
                    'year'        => $semester->year,
                    'name'        => $semester->name,
                    'campus'      => $semester->campus,
                    'session_key' => $semester->session_key
                );

                //convert obj to full-fledged ues_semester
                $ues = ues_semester::upgrade_and_get($semester, $params);

                if (empty($ues->classes_start)) {
                    continue;
                }

                // Call event before potential insert, as to notify creation
                events_trigger_legacy('ues_semester_process', $ues);

                //persist to {ues_semesters}
                $ues->save();

                // Fill in metadata from {enrol_ues_semestermeta}
                $ues->fill_meta();

                $processed[] = $ues;
            } catch (Exception $e) {
                $this->errors[] = $e->getMessage();
            }
        }

        return $processed;
    }

    /**
     * For each of the courses provided, instantiate as a ues_course
     * object; persist to the {enrol_ues_courses} table; then iterate
     * through each of its sections, instantiating and persisting each.
     * Then, assign the sections to the <code>course->sections</code> attirbute, 
     * and add the course to the return array.
     * 
     * @param ues_semester $semester
     * @param object[] $courses
     * @return ues_course[]
     */
    public function process_courses($semester, $courses) {
        $processed = array();

        foreach ($courses as $course) {
            try {
                $params = array(
                    'department' => $course->department,
                    'cou_number' => $course->cou_number
                );

                $ues_course = ues_course::upgrade_and_get($course, $params);

                events_trigger_legacy('ues_course_process', $ues_course);

                $ues_course->save();

                $processed_sections = array();
                foreach ($ues_course->sections as $section) {
                    $params = array(
                        'courseid'   => $ues_course->id,
                        'semesterid' => $semester->id,
                        'sec_number' => $section->sec_number
                    );

                    $ues_section = ues_section::upgrade_and_get($section, $params);

                    /*
                     * If the section does not already exist
                     * in {enrol_ues_sections}, insert it,
                     * marking its status as PENDING.
                     */
                    if (empty($ues_section->id)) {
                        $ues_section->courseid   = $ues_course->id;
                        $ues_section->semesterid = $semester->id;
                        $ues_section->status     = ues::PENDING;

                        $ues_section->save();
                    }

                    $processed_sections[] = $ues_section;
                }

                /*
                 * Replace the sections attribute of the course with
                 * the fully instantiated, and now persisted,
                 * ues_section objects.
                 */
                $ues_course->sections = $processed_sections;

                $processed[] = $ues_course;
            } catch (Exception $e) {
                $this->errors[] = $e->getMessage();
            }
        }

        return $processed;
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

            $this->process_teachers($section, $teachers, $current_teachers);
            $this->process_students($section, $students, $current_students);

            $this->release('teacher', $current_teachers);
            $this->release('student', $current_students);

            $this->post_section_process($semester, $course, $section);
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();

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

            // Specific release for instructor
            events_trigger_legacy('ues_' . $type . '_release', $user);

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

    private function post_section_process($semester, $course, $section) {
        // Process section only if teachers can be processed
        // Take into consideration outside forces manipulating
        // processed numbers through event handlers
        $by_processed = ues::where()
            ->status->in(ues::PROCESSED, ues::ENROLLED)
            ->sectionid->equal($section->id);

        $processed_teachers = ues_teacher::count($by_processed);

        // A section _can_ be processed only if they have a teacher
        // Further, this has to happen for a section to be queued
        // for enrollment
        if (!empty($processed_teachers)) {
            // Full section
            $section->semester = $semester;
            $section->course = $course;

            $previous_status = $section->status;

            $count = function ($type) use ($section) {
                $enrollment = ues::where()
                    ->sectionid->equal($section->id)
                    ->status->in(ues::PROCESSED, ues::PENDING);

                $class = 'ues_'.$type;

                return $class::count($enrollment);
            };

            $will_enroll = ($count('teacher') or $count('student'));

            if ($will_enroll) {
                // Make sure the teacher will be enrolled
                ues_teacher::reset_status($section, ues::PROCESSED, ues::ENROLLED);
                $section->status = ues::PROCESSED;
            }

            // Allow outside interaction
            events_trigger_legacy('ues_section_process', $section);

            if ($previous_status != $section->status) {
                $section->save();
            }
        }
    }

    public function process_teachers($section, $users, &$current_users) {
        return $this->fill_role('teacher', $section, $users, $current_users, function($user) {
            return array('primary_flag' => $user->primary_flag);
        });
    }

    /**
     * Process students.
     * 
     * This function passes params on to enrol_ues_plugin::fill_role() 
     * which does not return any value.
     * 
     * @see enrol_ues_plugin::fill_role()
     * @param ues_section $section
     * @param object[] $users
     * @param (ues_student | ues_teacher)[] $current_users
     * @return void 
     */
    public function process_students($section, $users, &$current_users) {
        return $this->fill_role('student', $section, $users, $current_users);
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

                events_trigger_legacy('ues_course_severed', $course);

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
            events_trigger_legacy('ues_primary_change', $data);

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
                        $this->errors[] = ues::_s('error_no_group', $group);
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
        $recover = $this->setting('recover_grades');

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
            $roleid = $this->setting($shortname . '_role');

            $this->enrol_user($instance, $user->userid, $roleid);

            groups_add_member($group->id, $user->userid);

            $recover_grades_for($user);

            $user->status = ues::ENROLLED;
            $user->save();

            $event_params = array(
                'group' => $group,
                'ues_user' => $user
            );

            events_trigger_legacy('ues_' . $shortname . '_enroll', $event_params);
        }
    }

    private function unenroll_users($group, $users) {
        global $DB;
        

        $instance = $this->get_instance($group->courseid);

        $course = $DB->get_record('course', array('id' => $group->courseid));

        foreach ($users as $user) {
            $shortname = $this->determine_role($user);

            $class = 'ues_' . $shortname;

            $roleid = $this->setting($shortname . '_role');

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

                events_trigger_legacy('ues_' . $shortname . '_unenroll', $event_params);
            }

        }

        $count_params = array('groupid' => $group->id);
        if (!$DB->count_records('groups_members', $count_params)) {
            // Going ahead and delete as delete
            groups_delete_group($group);
            events_trigger_legacy('ues_group_emptied', $group);
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

            $sn_pattern = $this->setting('course_shortname');
            $fn_pattern = $this->setting('course_fullname');

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
            events_trigger_legacy('ues_course_create', $moodle_course);

            try {
                $moodle_course = create_course($moodle_course);

                $this->add_instance($moodle_course);
            } catch (Exception $e) {
                $this->errors[] = ues::_s('error_shortname', $moodle_course);

                $course_params = array('shortname' => $moodle_course->shortname);
                $idnumber = $moodle_course->idnumber;

                $moodle_course = $DB->get_record('course', $course_params);
                $moodle_course->idnumber = $idnumber;

                if (!$DB->update_record('course', $moodle_course)) {
                    $this->errors[] = 'Could not update course: ' . $moodle_course->idnumber;
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
     * hint which caused problems for ues enrollment process_all()
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
            $user->email = $user->username . $this->setting('user_email');
        } else if ($prev = ues_user::get($by_username, true)) {
            $user->id = $prev->id;
        } else {
            global $CFG;
            //@todo take a close look here - watch primary flag
            $user->email = $user->username . $this->setting('user_email');
            $user->confirmed = $this->setting('user_confirm');
            $user->city = $this->setting('user_city');
            $user->country = $this->setting('user_country');
            $user->lang = $this->setting('user_lang');
            $user->firstaccess = time();
            $user->timecreated = $user->firstaccess;
            $user->auth = $this->setting('user_auth');
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

            events_trigger_legacy('user_updated', (object)$user);
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

    private function user_changed($prev, $current) {
        global $DB;
        $namefields   = user_picture::fields();
        $sql          = "SELECT id, idnumber, $namefields FROM {user} WHERE id = :id";

        // @TODO ues_user does not currently upgrade with the alt names.
        $previoususer = $DB->get_record_sql($sql, array('id'=>$prev->id));

        // Fullname requires alt name fields; make sure they exist.
        $altnames     = array_keys(get_all_user_name_fields());
        foreach($altnames as $a){
            if(!isset($current->$a)){
                $current->$a = null;
            }
        }

        if (fullname($previoususer) != fullname($current)){
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
     * 
     * @param string $type 'student' or 'teacher'
     * @param ues_section $section
     * @param object[] $users
     * @param ues_student[] $current_users all users currently registered in the UES tables for this section
     * @param callback $extra_params function returning additional user parameters/fields
     * an associative array of additional params, given a user as input
     */
    private function fill_role($type, $section, $users, &$current_users, $extra_params = null) {
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
                events_trigger_legacy($class . '_process', $ues_type);
            }
        }
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
    
    public function log($what) {
        if (!$this->is_silent) {
            mtrace($what);
        }

        $this->emaillog[] = $what;
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
