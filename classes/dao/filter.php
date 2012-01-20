<?php

interface ues_dao_dsl_words {
    public function like($value);
    public function starts_with($value);
    public function ends_with($value);
    public function equal($value);
    public function greater($value);
    public function less($value);
    public function greater_equal($value);
    public function less_equal($value);
    public function in();
    public function not_equal($value);
    public function is($value);
    public function is_not($value);
}

abstract class ues_dao_helpers {
    protected function clean($value) {
        if (is_numeric($value)) {
            return $value;
        } else if(is_null($value)) {
            return 'NULL';
        } else {
            return "'" . addslashes($value) . "'";
        }
    }
}

class ues_dao_filter {
    protected $fields;
    protected $current;

    function __construct($field = null) {
        if ($field) {
            $this->plus($field);
        }
    }

    function get() {
        return $this->fields;
    }

    function __toString() {
        return $this->sql();
    }

    function sql($handler = null) {
        $transform = function($field) use ($handler) {
            list($key, $built) = $field->get();

            if ($handler) {
                $key = $handler($key);
            }

            return $field->sql($key);
        };

        $transformed = array_map($transform, $this->fields);

        return implode(' AND ', $transformed);
    }

    function plus($field) {
        if (!isset($this->fields[$field])) {
            $this->current = new ues_dao_field($field);
            $this->fields[$field] = $this->current;
        }

        $this->current = $this->fields[$field];

        return $this;
    }

    // Delegate dsl words to current
    function __call($word, $args) {
        if (method_exists($this->current, $word)) {
            call_user_func_array(array($this->current, $word), $args);
            return $this;
        } else {
            return $this->plus($word);
        }
    }
}

class ues_dao_field extends ues_dao_helpers implements ues_dao_dsl_words {
    protected $built;
    protected $field;

    function __construct($field) {
        $this->field = $field;
        $this->built = array();
    }

    function key() {
        return $this->field;
    }

    function get() {
        if (empty($this->built))
            throw new Exception('Trying to build sql with empty field');

        return array($this->key(), $this->built);
    }

    function sql($key = null) {
        $use_field = $this->field;

        if ($key) {
            $use_field = $key;
        }

        $to_process = function($b) use($key) { return $key . ' ' . $b; };
        $processed = array_map($to_process, $this->built);

        return '(' . implode(' OR ', $processed) . ')';
    }

    protected function add_to($op) {
        $this->built[] = $op;
        return $this;
    }

    protected function comparison($op, $value) {
        return $this->add_to($op . ' ' . $this->clean($value));
    }

    function like($value) {
        return $this->add_to("LIKE '%".addslashes($value)."%'");
    }

    function starts_with($value) {
        return $this->add_to("LIKE '".addslashes($value)."%'");
    }

    function ends_with($value) {
        return $this->add_to("LIKE '%".addslashes($value)."'");
    }

    function in() {
        $values = func_get_args();
        $cleaned = array_map(array($this, 'clean'), $values);

        return $this->add_to('IN (' . implode(', ', $cleaned) . ')');
    }

    function equal($value) {
        return $this->comparison('=', $value);
    }

    function greater($value) {
        return $this->comparison('>', $value);
    }

    function less($value) {
        return $this->comparison('<', $value);
    }

    function greater_equal($value) {
        return $this->comparison('>=', $value);
    }

    function less_equal($value) {
        return $this->comparison('<=', $value);
    }

    function not_equal($value) {
        return $this->comparison('<>', $value);
    }

    function is($value) {
        return $this->comparison('is', $value);
    }

    function is_not($value) {
        return $this->comparison('is not', $value);
    }
}
