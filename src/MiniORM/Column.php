<?php

namespace MiniORM;

class Column {
    public function __construct(array $array) {
        foreach ($array as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
