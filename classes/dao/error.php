<?php
/**
 * @package enrol_ues
 */
interface ues_error_types {
    const COURSE = 'course';
    const DEPARTMENT = 'department';
    const SECTION = 'section';
    const CUSTOM = 'custom';
}

class ues_error extends ues_external implements ues_error_types {
    var $name;
    var $params;
    var $timestamp;

    public static function courses($semester) {
        return self::make(self::COURSE, array('semesterid' => self::id($semester)));
    }

    public static function department($semester, $department) {
        return self::make(self::DEPARTMENT, array(
            'semesterid' => self::id($semester),
            'department' => $department
        ));
    }

    public static function section($section) {
        return self::make(self::SECTION, array('sectionid' => self::id($section)));
    }

    public static function custom($handler, $params) {
        return self::make(self::CUSTOM, array(
            'handler' => $handler, 'params' => $params
        ));
    }

    public function restore() {
        $this->params = unserialize($this->params);
        return $this;
    }

    public function handle($enrollment) {
        $params = $this->restore()->params;

        switch ($this->name) {
            case self::COURSE:
                $semester = ues_semester::get(array('id' => $params['semesterid']));

                $enrollment->log('Reprocessing ' . $semester);

                $enrollment->process_semester($semester);
                break;
            case self::DEPARTMENT:
                $semester = ues_semester::get(array('id' => $params['semesterid']));
                $department = $params['department'];

                $ids = ues_section::ids_by_course_department($semester, $department);
                $sections = ues_section::get_all(ues::where()->id->in($ids));

                $enrollment->log('Reprocessing ' . $semester . ' ' . $department);

                $enrollment->process_enrollment_by_department(
                    $semester, $department, $sections
                );
                break;
            case self::SECTION:
                $section = ues_section::get(array('id' => $params['sectionid']));

                $semester = $section->semester();
                $course = $section->course();

                $enrollment->log('Reprocessing ' . $section);

                $enrollment->process_enrollment(
                    $semester, $course, $section
                );
                break;
            case self::CUSTOM:
                global $CFG;
                $handler = $params['handler'];

                // Safely attempt to run user code; keep error on failure;
                try {
                    $full_path = $CFG->dirroot . $handler->file;

                    if (isset($handler->file) and file_exists($full_path)) {
                        require_once $full_path;
                        $enrollment->log('Requiring ' . $full_path);
                    }

                    if (isset($handler->function) and is_callable($handler->function)) {
                        $local_params = array($enrollment, $params['params']);

                        $format = is_array($handler->function) ?
                            $handler->function[1] . ' on ' . $handler->function[0] :
                            $handler->function;

                        $enrollment->log('Calling function ' . $format);
                        call_user_func_array($handler->function, $local_params);
                    }
                } catch (Exception $e) {
                    return false;
                }
                break;
            default:
                // Don't handle it
                return false;
        }

        return true;
    }

    private static function id($obj) {
        return is_numeric($obj) ? $obj : $obj->id;
    }

    private static function make($type, $params) {
        $error = new ues_error();
        $error->name = $type;
        $error->params = serialize($params);
        $error->timestamp = time();

        return $error;
    }
}
