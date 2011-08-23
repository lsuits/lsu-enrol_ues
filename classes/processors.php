<?php

interface semester_processor {
    function semesters($date_threshold);
}

interface course_processor {
    function courses($semester);
}

interface teacher_processor {
    function teachers($semester, $course, $section);
}

interface student_processor {
    function students($semester, $course, $section);
}

interface teacher_info_processor {
    function teacher_info($semester, $teacher);
}
