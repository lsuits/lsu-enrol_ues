<?php

abstract class lsu_provider_error_handlers {
    private static function reprocess_source($provider, $source, $semesterid) {
        $semester = ues_semester::get(array('id' => $semesterid));

        $provider->process_data_source($source, $semester);
    }

    public static function reprocess_student_data($enrol, $params) {
        $provider = $enrol->provider();
        $source = $provider->student_data_source();

        self::reprocess_source($provider, $source, $params['semesterid']);
    }

    public static function reprocess_anonymous_numbers($enrol, $params) {
        $provider = $enrol->provider();
        $source = $provider->anonymous_source();

        self::reprocess_source($provider, $source, $params['semesterid']);
    }

    public static function reprocess_degree_candidates($enrol, $params) {
        $provider = $enrol->provider();
        $source = $provider->degree_source();

        self::reprocess_source($provider, $source, $params['semesterid']);
    }
}
