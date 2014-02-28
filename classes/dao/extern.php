<?php
/**
 * @package enrol_ues
 */
abstract class ues_external extends ues_base {
    public static function by_id($id) {
        return self::get(array('id' => $id));
    }

    public static function get_all($params = array(), $sort = '', $fields = '*', $offset = 0, $limit = 0) {
        return self::get_all_internal($params, $sort, $fields, $offset, $limit);
    }

    public static function get($params, $fields = '*') {
        return current(self::get_all($params, '', $fields));
    }

    public static function delete_all($params = array()) {
        return self::delete_all_internal($params);
    }
}
