<?php

function arrayToObject($array) {
    if (!is_array($array)) {
        return $array;
    }

    $object = new stdClass();
    foreach ($array as $key => $value) {
        $object->$key = arrayToObject($value);
    }
    return $object;
}
