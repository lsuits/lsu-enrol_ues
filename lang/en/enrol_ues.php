<?php

$string['pluginname'] = 'UES Enrollment';
$string['pluginname_desc'] = 'The UES (Universal Enrollment Service) module is
a pluggable enrollment system that adheres to common university criterion
including Semesters, Courses, Sections tied to coures, and teacher and student
enrollment tied to Sections.

The enrollment module will load any enrollment provider that handles the
`ues_list_provider`. A fully defined provider will show up in the dropdown below.';

$string['semester_cleanup'] = 'Semester Cleanup';
$string['reprocess_failures'] = 'Reprocess Failures';

$string['reprocess_count'] = 'Found {$a} error(s)';

$string['reprocess'] = 'Reprocess';
$string['reprocess_all'] = $string['reprocess'] . ' All';
$string['reprocess_selected'] = $string['reprocess'] . ' Selected';
$string['reprocess_success'] = 'Reprocessing errors';

$string['delete'] = 'Delete';
$string['delete_all'] = $string['delete'] . ' All';
$string['delete_selected'] = $string['delete'] . ' Selected';
$string['delete_success'] = 'Successfully deleted errors';

$string['no_errors'] = 'Congratulations! You have handled all the enrollment errors.';

$string['already_running'] = 'UES did not run, but it was supposed to. UES may have failed unexpectingly in the last run, or the request to cron.php may have been killed during the enrollment process. An admin should disable the running status by going to Settings -> Site Administration -> Plugins -> Enrolments -> UES Enrollment or {$a}. Once enabled, UES will run as expected.';

$string['cron_run'] = 'Daily Cron';
$string['cron_run_desc'] = 'Enable the daily cron run, or run cron manually.';

$string['sub_days'] = 'Semester in Day Range';
$string['sub_days_desc'] = 'How many days in the past (and future) should UES query
the semester source. This might be important for installing the system for the first
time.';

$string['could_not_enroll'] = 'Could not process enrollment for courses in {$a->year} {$a->name} {$a->campus} {$a->session_key}. Consider changing the Process by Department setting';

$string['recover_grades'] = 'Recover Grades';
$string['recover_grades_desc'] = 'Recover grade history grades on enrollment, if grades were present on unenrollment.';

$string['running'] = 'Currently Running';
$string['running_desc'] = 'If this is checked then it either means that the process is still running, or the process died unexpectingly. Uncheck this if you think the process should be enabled.

__Note__: One of the easiest ways to know the process has ended is to enable email logs.';

$string['ignore'] = 'Ignore';
$string['please_note'] = 'The following semesters were selected: {$a}';

$string['be_ignored'] = '{$a} - will be ignored';
$string['be_recoged'] = '{$a} - will be recognized';

$string['starttime'] = 'Last start time';
$string['starttime_desc'] = 'This the timestamp of its last started cron run. This timestamp differentiates itself from the _lastcron_, as this field represents when the cron started not finished.';

$string['grace_period'] = 'Grace Period';
$string['grace_period_desc'] = 'Wait this long (in seconds) after the _'.$string['starttime'].'_ before sending out the running notification. Typically, an hour is long enough, but some runs may exceed an hour.';

$string['cron_hour'] = 'Starting Hour';
$string['cron_hour_desc'] = 'Start the automatic cron on this hour.';

$string['error_threshold'] = 'Error Threshold';
$string['error_threshold_desc'] = 'The process will only automatically reprocess errors that occurred during the cron run whose numbers are less than or equal to the specified threshold.

__Note__: This setting only applies if _'.$string['cron_run'].'_ is enabled';

$string['error_threshold_log'] = 'There are too many errors to reprocess automatically. Either clear out the error queue through the settings page, or raise the threshold number.';

$string['error_params'] = 'Parameters';
$string['error_when'] = 'Timestamp';
$string['error_shortname'] = 'Tried to create a course, but failed because the course appears to have already been created: {$a->shortname}';

$string['error_no_group'] = 'UES tried to add someone to the deleted group (name = {$a->name}) for course (id = {$a->courseid}). UES has reason to believe that this group should not be in existence (no more teachers are enrolled). Please verify the UES enrollment data (unmanifested entries) in the selected course, and file a bug report if the data looks sound and should have been manifested.';

$string['semester_ignore'] = 'Semester Ignore';

$string['general_settings'] = 'General Settings';
$string['management'] = 'Internal Links';
$string['management_links'] = '
Below are some internal links to manage the enrollment data.

* ['.$string['semester_cleanup'].']({$a->cleanup_url})
* ['.$string['semester_ignore'].']({$a->ignore_url})
* ['.$string['reprocess_failures'].']({$a->failure_url})
';

$string['email_report'] = 'Email Logs';
$string['email_report_desc'] = 'Email UES execution log to all admins.

__Note__: Any errors will be reported regardless.';

$string['user_settings'] = 'User Creation Settings';
$string['user_email'] = 'E-mail suffix';
$string['user_email_desc'] = 'The created user will have this email domain appended to their username.';
$string['user_confirm'] = 'Confirmed';
$string['user_confirm_desc'] = 'The user will be _confirmed_ upon creation.';
$string['user_city'] = 'City/town';
$string['user_city_desc'] = 'The created user will have this default city assigned to them.';
$string['user_country'] = 'Country';
$string['user_country_desc'] = 'The created user will have this default country assigned to them.';
$string['user_auth'] = 'Authentication Method';
$string['user_auth_desc'] = 'The created user will have this authentication method assigned to them.';

$string['course_settings'] = 'Course Creation Settings';
$string['course_visible_desc'] = 'Upon creation the course will be visible to students.';
$string['course_shortname_desc'] = 'Generated Shortname for the course';
$string['course_shortname'] = '{year} {name} {department} {session}{course_number} for {fullname}';
$string['course_fullname_desc'] = 'Generated Fullname for the course';
$string['course_fullname'] = '{year} {name} {department} {session}{course_number} for {fullname}';

$string['course_form_replace'] = 'Replace course form';
$string['course_form_replace_desc'] = 'Displays a more friendly version of the
course form';

$string['course_restricted_fields'] = 'Restricted form fields';
$string['course_restricted_fields_desc'] = 'Will not allow the user to edit the
selected form fields. Fields not listed in the select means there is a capability
to hide the fields

__Note__: If used in conjuction with _Replace course form_, then the selected fields will be hidden.';

$string['bad_field'] = 'This setting cannot be changed.';

$string['provider'] = 'Enrollment Provider';
$string['provider_desc'] = 'This enrollment provider will be used to pull enrollment data.';

$string['process_by_department'] = 'Process by Department';
$string['process_by_section'] = 'Process by Section';
$string['reverse_lookups'] = 'Reverse Lookups';
$string['process_by_department_desc'] = 'This setting will make UES query enrollment by department
instead of sections. For network queries, this option may be more efficient.';

$string['provider_information'] = 'Provider Information';
$string['provider_information_desc'] = '__{$a->name}__ supports the following methods: <ul>{$a->list}</ul>';

$string['provider_problems'] = 'Provider Cannot be Instantiated';
$string['provider_problems_desc'] = '
_{$a->pluginname}_ cannot be instantiated with the current settings.

__Problem__: {$a->problem}

This will cause the enrollment plugin to abort in cron. Please address
these errors.

__Note to Developers__: Consider using the `adv_settings` for server side
validation of settings.';

$string['no_provider'] = 'No Enrollment Provider selected.';

$string['provider_settings'] = '{$a} Settings';

$string['provider_cron_problem'] = 'Could not instantiate {$a->pluginname}: {$a->problem}. Check provider configuration.';
$string['enrollment_unsupported'] = 'Provider does not fully support either
teacher_source() / student_source() or teacher_department_source() / student_department_source()
enrollment source';

$string['enrol_settings'] = 'User Enrollment Settings';
$string['student_role'] = 'Students';
$string['student_role_desc'] = 'UES students will be enrolled in this Moodle role';
$string['editingteacher_role'] = 'Primary Instructor';
$string['editingteacher_role_desc'] = 'UES *primary* teachers will be enrolled in this Moodle role';
$string['teacher_role'] = 'Non-Primary Instructor';
$string['teacher_role_desc'] = 'UES *non-primary* teachers will be enrolled in this Moodle role';

$string['failed_sem'] = 'The following semester does not have an end date: {$a->year} {$a->name} {$a->campus} {$a->session_key}';

/** Behavior Strings go here */
$string['lsu_name'] = 'LSU Enrollment Provider';

$string['lsu_credential_location'] = 'Credential Location';
$string['lsu_credential_location_desc'] = 'For security purposes, the login credentials for the LSU web service is stored on a local secure server. This is the complete url to access the credentials.';

$string['lsu_wsdl_location'] = 'SOAP WSDL';
$string['lsu_wsdl_location_desc'] = 'This is the wsdl used in SOAP requests to LSU\'s Data Access Service. The Moodle data directory *{$a->dataroot}* is assumed as the path base.';

$string['lsu_bad_file'] = 'Provide a *.wsdl* file';
$string['lsu_no_file'] = 'The WSDL does not exists in wsdl_location';
$string['lsu_bad_url'] = 'Provide a valid url (define either a http or https protocol)';
$string['lsu_bad_resp'] = 'Invalid credentials in credential location request';

$string['lsu_student_data'] = 'Process Student Data';
$string['lsu_student_data_desc'] = 'This will enable processing student data in the `postprocess` section of the LSU provider';

$string['lsu_anonymous_numbers'] = 'Process LAW Numbers';
$string['lsu_anonymous_numbers_desc'] = 'This will enable processing anonymous numbers in the `postprocess` section of the LSU provider';

$string['lsu_degree_candidates'] = 'Process Degree Candidacy';
$string['lsu_degree_candidates_desc'] = 'This will enabled processing degree candidate information in the `postprocess` section of the LSU provider';

$string['lsu_sports_information'] = 'Sports Information';
$string['lsu_sports_information_desc'] = 'This will enable the pulling of student athletic information in the `postprocess` section of the LSU provider';

$string['lsu_semester_source'] = 'Semester serviceId';
$string['lsu_semester_source_desc'] = 'The web service id for campus semesters';

$string['lsu_course_source'] = 'Courses serviceId';
$string['lsu_course_source_desc'] = 'The web service id for courses per semester';

$string['lsu_teacher_by_department'] = 'Dept instructors serviceId';
$string['lsu_teacher_by_department_desc'] = 'The web service id for all instructors in a given department';

$string['lsu_student_by_department'] = 'Dept students serviceId';
$string['lsu_student_by_department_desc'] = 'The web service id for all students in a given department';

$string['lsu_teacher_source'] = 'Sec instructor serviceId';
$string['lsu_teacher_source_desc'] = 'The web service id for all instructors in a given section';

$string['lsu_student_source'] = 'Sec student serviceId';
$string['lsu_student_source_desc'] = 'The web service id for all students in a given section';

$string['lsu_student_data_source'] = 'Student data serviceId';
$string['lsu_student_data_source_desc'] = 'the web service id for all student data in a given semester';

$string['lsu_student_degree_source'] = 'Degree candidate serviceId';
$string['lsu_student_degree_source_desc'] = 'The web service id for degree candidate info for a given semester';

$string['lsu_student_anonymous_source'] = 'Anonymous # serviceId';
$string['lsu_student_anonymous_source_desc'] = 'The web service id for anonymous numbers';

$string['lsu_student_ath_source'] = 'Athlete info serviceId';
$string['lsu_student_ath_source_desc'] = 'The web service id for student athletes';

// Fake for testing
$string['fake_name'] = 'Fake Source Enrollment Provider';
$string['fake_course_variant'] = 'Course Variations';
$string['fake_course_variant_desc'] = 'These are course department variations.';
$string['fake_section_variant'] = 'Section Variation';
$string['fake_section_variant_desc'] = 'These are the section varation for each course department.';
$string['fake_teacher_variant'] = 'Teacher Variation';
$string['fake_teacher_variant_desc'] = 'Teachers per section (min: 1)';
$string['fake_student_variant'] = 'Student Variation';
$string['fake_student_variant_desc'] = 'Students per section (min: 10)';

$string['fake_invalid_student'] = 'Must have at least one student';
$string['fake_invalid_teacher'] = 'Must have at least one teacher';
$string['fake_invalid_course'] = 'Must have at least one course';
$string['fake_invalid_sections'] = 'Must have at least one section';

$string['fake_linkables'] = 'Fake Provider External Links';
$string['fake_cleanup'] = 'Cleanup';
$string['fake_cleanup_desc'] = 'Warning: this runs truncate on all the UES tables.';

$string['fake_cleanuprun'] = 'Cleanup on run';
$string['fake_cleanuprun_desc'] = 'Runs _Cleanup_ in provider `postprocess`';

$string['no_semester'] = 'The semester you have selected does not exists.';
$string['no_semesters'] = 'There are no semesters in your system. Consider running the enrollment process.';

$string['drop_semester'] = 'Drop {$a->year} {$a->name} {$a->campus} {$a->session_key} and all associated data';
$string['year'] = 'Year';
$string['campus'] = 'Campus';
$string['session_key'] = 'Session';
$string['sections'] = 'Sections';
$string['in_session'] = 'In Session?';
