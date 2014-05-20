<?php

require_once dirname(__FILE__) . '/lib.php';

class ues_semester extends ues_dao {
    var $sections;
    var $courses;

    public static function in_session($when = null) {
        if (empty($when)) {
            $when = time();
        }

        $filters = ues::where()
            ->classes_start->less_equal($when)
            ->grades_due->greater_equal($when)->is(NULL);

        return self::get_all($filters, true);
    }

    public function sections() {
        if (empty($this->sections)) {
            $sections = ues_section::get_all(array('semesterid' => $this->id));

            $this->sections = $sections;
        }

        return $this->sections;
    }

    public function get_session_key() {
        $s = empty($this->session_key) ? '' : ' (' . $this->session_key . ')';

        return $s;
    }

    public static function merge_sections(array $sections) {
        $semesters = array();

        foreach ($sections as $section) {
            $semesterid = $section->semesterid;

            // Work on different semesters
            if (isset($semesters[$semesterid])) {
                continue;
            }

            $semester = $section->semester();
            $semester->courses = ues_course::merge_sections($sections, $semester);

            $semesters[$semesterid] = $semester;
        }

        return $semesters;
    }

    public function __toString() {
        $session = $this->get_session_key();
        return sprintf('%s %s%s at %s', $this->year, $this->name, $session, $this->campus);
    }
}

class ues_course extends ues_dao {
    var $sections;
    var $teachers;
    var $student;

    public static function get_departments($filter = null) {
        global $DB;

        $safe_filter = $filter ? "WHERE department = '".addslashes($filter)."'":'';

        $sql = "SELECT DISTINCT(department)
                    FROM {enrol_ues_courses} $safe_filter ORDER BY department";

        return array_keys($DB->get_records_sql($sql));
    }

    public static function flatten_departments($courses) {
        $departments = array();

        foreach ($courses as $course) {
            if (!isset($departments[$course->department])) {
                $departments[$course->department] = array();
            }

            $departments[$course->department][] = $course->id;
        }

        return $departments;
    }

    public static function by_department($dept) {
        return ues_course::get_all(array('department' => $dept), true);
    }

    public static function merge_sections(array $sections, $semester = null) {
        $courses = array();

        foreach ($sections as $section) {
            $courseid = $section->courseid;

            // Filter on semester
            if ($semester and $section->semesterid != $semester->id) {
                continue;
            }

            if (!isset($courses[$courseid])) {
                $course = $section->course();
                $course->sections = array();
                $courses[$courseid] = $course;
            }

            $courses[$courseid]->sections[$section->id] = $section;
        }

        return $courses;
    }

    public function teachers($semester = null, $is_primary = true) {
        if (empty($this->teachers)) {
            $filters = $this->section_filters($semester);

            if ($is_primary) {
                $filters->primary_flag->equal(1);
            }

            $this->teachers = ues_teacher::get_all($filters);
        }

        return $this->teachers;
    }

    public function students($semester = null) {
        if (empty($this->students)) {
            $filters = $this->section_filters($semester);

            $this->students = ues_student::get_all($filters);
        }

        return $this->students;
    }

    public function sections($semester = null) {
        if (empty($this->sections)) {
            $by_params = array('courseid' => $this->id);

            if ($semester) {
                $by_params['semesterid'] = $semester->id;
            }

            $this->sections = ues_section::get_all($by_params);
        }

        return $this->sections;
    }

    public function __toString() {
        return sprintf('%s %s', $this->department, $this->cou_number);
    }

    private function section_filters($semester = null) {
        $sectionids = array_keys($this->sections($semester));

        $filters = ues::where()
            ->sectionid->in($sectionids)
            ->status->equal(ues::PROCESSED)->equal(ues::ENROLLED);

        return $filters;
    }
}

class ues_section extends ues_dao {
    var $semester;
    var $course;

    var $moodle;
    var $group;

    var $primary;
    var $teachers;

    var $students;
    //important
    protected function qualified() {
        return ues::where()
            ->sectionid->equal($this->id)
            ->status->in(ues::ENROLLED, ues::PROCESSED);
    }

    public function primary() {
        if (empty($this->primary)) {
            $teachers = $this->teachers();

            $primaries = function ($t) { return $t->primary_flag; };

            $this->primary = current(array_filter($teachers, $primaries));
        }

        return $this->primary;
    }

    public function teachers() {
        if (empty($this->teachers)) {
            $this->teachers = ues_teacher::get_all($this->qualified());
        }

        return $this->teachers;
    }

    public function students() {
        if (empty($this->students)) {
            $this->students = ues_student::get_all($this->qualified());
        }

        return $this->students;
    }

    public function semester() {
        if (empty($this->semester)) {
            $semester = ues_semester::get(array('id' => $this->semesterid));

            $this->semester = $semester;
        }

        return $this->semester;
    }

    public function course() {
        if (empty($this->course)) {
            $course = ues_course::get(array('id' => $this->courseid));

            $this->course = $course;
        }

        return $this->course;
    }

    public function moodle() {
        if (empty($this->moodle) and !empty($this->idnumber)) {
            global $DB;

            $course_params = array('idnumber' => $this->idnumber);
            $this->moodle = $DB->get_record('course', $course_params);
        }

        return $this->moodle;
    }

    public function group() {
        if (!$this->is_manifested()) {
            return null;
        }

        if (empty($this->group)) {
            global $DB;

            $course = $this->course();
            $moodle = $this->moodle();
            $name = "$course->department $course->cou_number $this->sec_number";

            $params = array('name' => $name, 'courseid' => $moodle->id);

            $this->group = $DB->get_record('groups', $params);
        }

        return $this->group;
    }

    public function is_manifested() {
        global $DB;

        // Clearly it hasn't
        if (empty($this->idnumber)) {
            return false;
        }

        $moodle = $this->moodle();

        return $moodle ? true : false;
    }

    public function __toString() {
        if ($this->course and $this->semester) {
            $course = $this->course;
            $semester = $this->semester;

            return sprintf('%s %s %s %s %s', $semester->year, $semester->name,
                $course->department, $course->cou_number, $this->sec_number);
        }

        return 'Section '. $this->sec_number;
    }

    /** Expects a Moodle course, returns an optionally full ues_section */
    public static function from_course(stdClass $course, $fill = false) {
        if (empty($course->idnumber)) {
            return array();
        }

        $sections = ues_section::get_all(array('idnumber' => $course->idnumber));

        if ($sections and $fill) {
            foreach ($sections as $section) {
                $section->course();
                $section->semester();
                $section->moodle = $course;
            }
        }

        return $sections;
    }

    public static function ids_by_course_department($semester, $department) {
        global $DB;

        $sql = 'SELECT sec.*
                FROM {enrol_ues_sections} sec,
                     {enrol_ues_courses} cou
                     WHERE sec.courseid = cou.id
                       AND sec.semesterid = :semid
                       AND cou.department = :dept';

        $params = array('semid' => $semester->id, 'dept' => $department);

        return array_keys($DB->get_records_sql($sql, $params));
    }
}

abstract class user_handler extends ues_dao {
    var $section;
    var $user;

    protected function qualified($by_status = null) {
        $filters = ues::where()->userid->equal($this->userid);

        if (empty($by_status)) {
            $filters->status->in(ues::ENROLLED, ues::PROCESSED);
        } else {
            $filters->status->equal($by_status);
        }

        return $filters;
    }

    public function sections_by_status($status) {
        $params = $this->qualified($status);

        $by_status = self::call('get_all', $params);

        $sections = array();
        foreach ($by_status as $state) {
            $section = $state->section();
            $sections[$section->id] = $section;
        }

        return $sections;
    }

    public function section() {
        if (empty($this->section)) {
            $section = ues_section::get(array('id' => $this->sectionid));

            $this->section = $section;
        }

        return $this->section;
    }

    public function user() {
        if (empty($this->user)) {

            $username_fields = user_picture::fields();

            $user = ues_user::get(array('id' => $this->userid), true,
                "{$username_fields}, username, idnumber");
            $this->user = $user;
        }

        return $this->user;
    }

    public static function reset_status($section, $to = 'pending', $from = 'enrolled') {
        if (is_object($section)) {
            $section = $section->id;
        }

        $class = get_called_class();

        $class::update(
            array('status' => $to),
            ues::where()->sectionid->in($section)->status->equal($from)
        );
    }
}

class ues_teacher extends user_handler {
    var $sections;

    public function sections($is_primary = false) {
        if (empty($this->sections)) {
            $qualified = $this->qualified();

            if ($is_primary) {
                $qualified->primary_flag->equal(1);
            }

            $all_teaching = ues_teacher::get_all($qualified);
            $sections = array();
            foreach ($all_teaching as $teacher) {
                $section = $teacher->section();
                $sections[$section->id] = $section;
            }

            $this->sections = $sections;
        }

        return $this->sections;
    }
}

class ues_student extends user_handler {
    var $sections;

    public function sections() {
        if (empty($this->sections)) {
            $all_students = ues_student::get_all($this->qualified());

            $sections = array();
            foreach ($all_students as $student) {
                $section = $student->section();
                $sections[$section->id] = $section;
            }

            $this->sections = $sections;
        }

        return $this->sections;
    }
}

class ues_user extends ues_dao {

    public static function tablename($alias='') {
        return !empty($alias) ? "{user} $alias" : 'user';
    }

    private static function qualified($userid = null) {
        if (!$userid) {
            global $USER;
            $userid = $USER->id;
        }

        $filters = ues::where()
            ->userid->equal($userid)
            ->status->in(ues::PROCESSED, ues::ENROLLED);

        return $filters;
    }

    public static function is_teacher($userid = null) {
        $count = ues_teacher::count(self::qualified($userid));

        return !empty($count);
    }

    public static function is_teacher_in($sections, $primary = false, $userid = null) {
        $filters = self::qualified($userid);

        $filters->sectionid->in(array_keys($sections));

        if ($primary) {
            $filters->primary_flag->equal(1);
        }

        $count = ues_teacher::count($filters);
        return !empty($count);
    }

    public static function sections($primary = false) {
        if (!self::is_teacher()) {
            return array();
        }

        $teacher = current(ues_teacher::get_all(self::qualified()));

        return $teacher->sections($primary);
    }
}
