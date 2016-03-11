<?php

class UESGuardException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null) {
        // $message = 'UES Guard: ' . $message;

        parent::__construct($message, $code, $previous);
    }

    // custom string representation of object
    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}