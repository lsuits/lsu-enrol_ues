<?php
/**
 * @package enrol_ues
 */
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
    public function not_in();
    public function not_equal($value);
    public function is($value);
    public function is_not($value);
}

abstract class ues_dao_helpers {
    protected function clean($value) {
        if (is_int($value) or is_double($value)) {
            return $value;
        } else if (is_null($value)) {
            return 'NULL';
        } else {
            return "'" . addslashes(trim($value)) . "'";
        }
    }
}

abstract class ues_dao_filter_builder implements IteratorAggregate {
    protected $fields;
    protected $current;
    protected $joins = array();

    protected $memoized;

    abstract function create_field($field);

    function __construct($field = null) {
        if ($field) {
            $this->plus($field);
        }
    }

    public function getIterator() {
        return new ArrayIterator($this->get());
    }

    function is_empty() {
        return empty($this->fields);
    }

    function get() {
        if (empty($this->fields)) {
            throw new Exception("Intent to filter, but no fields specified");
        }

        return $this->fields;
    }

    function join_sql($alias = null) {
        $joins = $alias ? $alias : '';;
        foreach ($this->joins as $alias => $statement) {
            $joins .= ", $statement $alias";
        }

        return $joins;
    }

    function __toString() {
        return $this->sql();
    }

    function join($statement, $alias) {
        $this->joins[$alias] = $statement;
        end($this->joins);
        return $this;
    }

    function on($fieldkey, $joinfield, $alias = null) {
        if (empty($this->joins)) {
            throw new Exception('Cannot perform join without a join declaration.');
        }

        $alias = $alias ? $alias : key($this->joins);
        return $this->$fieldkey->raw('= ' . $alias . '.' . $joinfield);
    }

    function sql($handler = null) {
        $transform = function($field) use ($handler) {
            list($key, $built) = $field->get();

            if ($handler) {
                $key = $handler($key, $field);
            }

            return $field->sql($key);
        };

        $transformed = array_map($transform, $this->get());

        return implode(' AND ', $transformed);
    }

    function plus($field) {
        if (!isset($this->fields[$field])) {
            $this->current = $this->create_field($field);
            $this->fields[$field] = $this->current;
        }

        $this->current = $this->fields[$field];

        return $this;
    }

    // Delegate dsl words to current
    function __call($word, $args) {
        if (!method_exists($this->current, $word)) {
            throw new Exception('Trying to build ' . $word . ' but field does not support it');
        }

        call_user_func_array(array($this->current, $word), $args);
        return $this;
    }

    function __get($name) {
        if (!empty($this->memoized)) {
            $memoized = $this->memoized;
            unset($this->memoized);

            return $this->plus($memoized . '.' . $name);
        } else if (isset($this->joins[$name])) {
            $this->memoized = $name;
            return $this;
        }

        return $this->plus($name);
    }
}

class ues_dao_filter extends ues_dao_filter_builder {
    function create_field($field) {
        return new ues_dao_field($field);
    }
}

class ues_dao_field extends ues_dao_helpers implements ues_dao_dsl_words {
    protected $built;
    protected $field;
    protected $aliased;

    function __construct($field) {
        $this->field = $field;
        $this->aliased = preg_match('/\./', $field);
        $this->built = array();
    }

    function key() {
        return $this->field;
    }

    function is_aliased() {
        return $this->aliased;
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

    protected function arg_handler($values) {
        if (is_array(current($values))) {
            $values = current($values);
        }

        return array_map(array($this, 'clean'), $values);
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
        $cleaned = $this->arg_handler(func_get_args());
        return $this->add_to('IN (' . implode(', ', $cleaned) . ')');
    }

    function not_in() {
        $cleaned = $this->arg_handler(func_get_args());
        return $this->add_to('NOT IN (' . implode(', ', $cleaned) . ')');
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

    function raw($sql) {
        return $this->add_to($sql);
    }
}
