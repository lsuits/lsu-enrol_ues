<?php

abstract class ues_base {
    /** Protected static helper function to maintain calling class static
     * overrides
     */
    protected static function with_class($fun) {
        return $fun(get_called_class());
    }

    protected static function call($fun, $params = array()) {
        return self::with_class(function ($class) use ($fun, $params) {
            return call_user_func(array($class, $fun), $params);
        });
    }

    protected static function by_id($id) {
        return self::get_internal(array('id' => $id));
    }

    protected static function get_internal($params, $fields = '*', $trans = null) {
        return current(self::get_all_internal($params, '', $fields, $trans));
    }

    protected static function get_all_internal($params = array(), $sort = '', $fields='*', $trans = null) {
        global $DB;

        $tablename = self::call('tablename');

        if (is_array($params)) {
            $res = $DB->get_records($tablename, $params, $sort, $fields);
        } else {
            $where = $params->sql();

            $order = !empty($sort) ? ' ORDER BY '. $sort : '';

            $sql = 'SELECT '.$fields.' FROM {'.$tablename.'} WHERE '.$where . $order;

            $res = $DB->get_records_sql($sql);
        }

        $ret = array();
        foreach ($res as $r) {
            $temp = self::call('upgrade', $r);

            $ret[$r->id] = $trans ? $trans($temp) : $temp;
        }

        return $ret;
    }

    protected static function delete_all_internal($params = array(), $trans = null) {
        global $DB;

        $tablename = self::call('tablename');

        $to_delete = $DB->count_records($tablename, $params);

        if ($trans and $to_delete) {
            $trans($tablename);
        }

        return $DB->delete_records($tablename, $params);
    }

    public static function count($params = array()) {
        global $DB;

        $tablename = self::call('tablename');

        if (is_array($params)) {
            return $DB->count_records($tablename, $params);
        } else {
            $where = $params->sql();
            $sql = 'SELECT COUNT(*) FROM {' . $tablename . '} WHERE ' . $where;

            return $DB->count_records_sql($sql);
        }
    }

    public static function update(array $fields, $params = array()) {
        global $DB;

        list($map, $trans) = self::update_helpers();

        list($set_params, $set_keys) = $trans('set', $fields);

        $set = implode(' ,', $set_keys);

        $sql = 'UPDATE {' . self::call('tablename') .'} SET ' . $set;

        if ($params and is_array($params)) {
            $where_keys = array_keys($params);
            $where_params = array_map($map, $where_keys, $where_keys);

            $where = implode(' AND ', $where_params);

            $sql .= ' WHERE ' . $where;

            $set_params += $params;
        } else if($params) {
            $sql .= ' WHERE ' . $params->sql();
        }

        return $DB->execute($sql, $set_params);
    }

    private static function update_helpers() {
        $map = function ($key, $field) { return "$key = :$field"; };

        $trans = function ($new_key, $fields) use ($map) {
            $oldkeys = array_keys($fields);

            $newnames = function ($field) use ($new_key) {
                return "{$new_key}_{$field}";
            };

            $newkeys = array_map($newnames, $oldkeys);

            $params = array_map($map, $oldkeys, $newkeys);

            $new_params = array_combine($newkeys, array_values($fields));
            return array($new_params, $params);
        };

        return array($map, $trans);
    }

    public static function get_name() {
        $names = explode('_', get_called_class());
        return implode('_', array_slice($names, 1));
    }

    public static function tablename() {
        return sprintf('enrol_%s', get_called_class() . 's');
    }

    public static function upgrade($db_object) {
        return self::with_class(function ($class) use ($db_object) {

            $fields = $db_object ? get_object_vars($db_object) : array();

            // Children can handle their own instantiation
            $self = new $class($fields);

            return $self->fill_params($fields);
        });
    }

    /** Instance based interaction */
    public function fill_params(array $params = array()) {
        if (!empty($params)) {
            foreach ($params as $field => $value) {
                $this->$field = $value;
            }
        }

        return $this;
    }

    public function save() {
        global $DB;

        $tablename = self::call('tablename');

        if (!isset($this->id)) {
            $this->id = $DB->insert_record($tablename, $this, true);
        } else {
            $DB->update_record($tablename, $this);
        }

        return true;
    }

    public static function delete($id) {
        global $DB;

        return $DB->delete_records(self::call('tablename'), array('id' => $id));
    }

}

