<?php

abstract class ues_external extends ues_base {
    public static function get_all(array $params = array(), $sort = '', $fields = '*') {
        return self::get_all_internal($params, $sort, $fields);
    }

    public static function get(array $params, $fields = '*') {
        return current(self::get_all($params, '', $fields));
    }

    public static function get_select($filters, $sort = '') {
        return self::get_select_internal($filters, $sort);
    }

    public static function delete_all(array $params = array()) {
        return self::delete_all_internal($params);
    }
}
