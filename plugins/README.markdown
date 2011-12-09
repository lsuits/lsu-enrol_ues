# UES Provider Plugins

Those wanting to add specific behavior to the UES enrollment process should create
a _UES Provider_.


UES provides an API through inheritance of the
[enrollment_provider](https://github.com/lsuits/ues/blob/master/classes/provider.php) abstract class.

A provider must override the following sources:

 * `semester_source`: returns an array of semesters or `ues_semester`s.
 * `course_source`: returns an array of course and sections or `ues_course`s.
 * `teacher_source`: returns an array of teachers or `ues_teacher`s.
 * `student_source`: returns an array of students or `ues_student`s.
